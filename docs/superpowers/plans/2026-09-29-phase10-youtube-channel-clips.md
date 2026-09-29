# Phase 10 — YouTube channel clips Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Додати канали YouTube → авто-виявлення нових відео → завантаження → транскрипція → LLM-нарізка на кліпи (або ціле відео) → рендер 9:16 з оригінальним звуком і вигорілими субтитрами → існуючий quality-check/Approve/Publish.

**Architecture:** Новий bounded context `app/Domain/Source/` (вибір кліпів, чиста логіка + завантажувач), ланцюжок Jobs (як решта пайплайна, без оркестратора), кліп = звичайний `Video` з одним `VideoScene` без `Voiceover` (`videos.source_clip_id`); `RenderVideoJob` вибирає `SourceClipRenderer` за `source_clip_id`. LLM обирає межі кліпів за **id реплік**, секунди бере код.

**Tech Stack:** Laravel 12 / PHP 8.4, PostgreSQL, Filament 4, ffmpeg/ffprobe, faster-whisper (наявний CLI-провайдер), yt-dlp (нове), PHPUnit (не Pest).

**Spec:** `docs/superpowers/specs/2026-09-29-phase10-youtube-channel-clips-design.md`

## Global Constraints

- Мова коментарів/повідомлень у коді — як у сусідніх файлах (англійська в коді, українська в docs). Відповіді користувачу — українською.
- Стиль: `vendor/bin/pint` (дефолти Laravel) перед кожним комітом; `vendor/bin/pint --test` має бути чистим на нових/змінених файлах.
- Тести: PHPUnit-класи, методи `test_it_<опис>()`; **жодних реальних HTTP/процесів** (`Process::fake`, `Http::fake`, `Fake*` двійники). `Unit`-тести чистої логіки — `PHPUnit\Framework\TestCase` (без БД/`config()`); Feature — `Tests\TestCase` + `RefreshDatabase`. Job-тести: `Queue::fake()` у `setUp()`.
- Запуск тестів: `php artisan test --filter=<Name>` (потрібен `docker compose up -d postgres redis` і `.env.testing`, див. `docs/testing.md`).
- Кожен зовнішній інтеграційний клас має `Fake*`-двійник; провайдери біндяться в `*ServiceProvider::register()`.
- Pipeline — ланцюжок Jobs: кожен job у `handle()` сам диспатчить наступний; `failed(Throwable)` пише стан + `notifyPermanentFailure('video', ...)` (trait `NotifiesOnPermanentFailure`); `ShouldBeUnique` + `uniqueId()`; `backoff()`.
- Робоче дерево має **чужі незакомічені зміни** (`app/Jobs/CollectVideoAssetsJob.php`, `app/Jobs/GenerateVoiceoverJob.php`, `docker-compose.yml`, `docker/php/Dockerfile`, `docker/worker/Dockerfile`, `database/migrations/2026_09_27_120000_widen_content_ideas_topic_column.php`, `examples/`). **Ніколи** `git add -A` / `git add .`; додавай лише явні шляхи своєї задачі. Ці файли не чіпати, крім `docker/worker/Dockerfile` у Task 5 (змінити, але **не стейджити** — повідомити контролера).
- Комміти: закінчувати повідомлення рядком `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Робота іде в гілці `phase10-youtube-clips`.
- Велике джерело не можна вантажити в пам'ять цілком (PHP `memory_limit=512M`): читати/писати файли лише потоками (`readStream` + `stream_copy_to_stream`, `$disk->put($path, $resource)`).

## Review Focus

- Відео без аудіодоріжки → рендер додає тишу (`anullsrc`), щоб quality-check `has_audio_stream` не падав.
- Порожній транскрипт / відео без мовлення → `SourceVideo.status = no_clips`, а не failed.
- LLM повертає id, яких нема, `start_id > end_id`, перетини, занадто довгі/короткі кліпи, відсутній `score` → repair-цикл, потім `ClipSelectionFailedException`.
- Повторний poll / повторний запуск job не дублює `SourceVideo` (unique `youtube_id`) і не створює другий `Video` для кліпа з `video_id`.
- Дуже довгі джерела: transcript розбивається на вікна з перекриттям; `whole`-режим із довгим відео може перевищити `RenderVideoJob::$timeout = 900` (таймаут ffmpeg у рендері масштабується від тривалості кліпа).
- Whisper на довгому аудіо потребує великого `WHISPER_TIMEOUT` (задокументувати в `docs/GUIDE.md`).

---

## File Structure

Create:
- `database/migrations/2026_09_29_100000_create_source_channels_table.php`, `..._100100_create_source_videos_table.php`, `..._100200_create_source_clips_table.php`, `..._100300_add_source_clip_id_to_videos_table.php`
- `app/Models/{SourceChannel,SourceVideo,SourceClip}.php`, `app/Models/Enums/{SourceChannelMode,SourceChannelFraming,SourceVideoStatus}.php`, `database/factories/{SourceChannel,SourceVideo,SourceClip}Factory.php`
- `config/clips.php`
- `app/Domain/Source/Support/{Utterance,UtteranceBuilder,TranscriptPromptFormatter,SubtitleSlicer,Clip,ClipConstraints,ClipValidator,ClipMerger,TranscriptWindower}.php`
- `app/Domain/Source/Exceptions/{InvalidClipSelectionException,ClipSelectionFailedException}.php`
- `app/Domain/Source/Services/ClipSelector.php`
- `app/Domain/Source/{YoutubeDownloaderInterface}.php`, `app/Domain/Source/Providers/{YtDlpDownloader,FakeYoutubeDownloader}.php`, `app/Providers/SourceServiceProvider.php`
- `app/Domain/Video/Providers/SourceClipRenderer.php`
- `app/Jobs/{DiscoverSourceVideosJob,DownloadSourceVideoJob,TranscribeSourceVideoJob,SelectClipsJob,CreateClipVideosJob}.php`
- `app/Console/Commands/{AddSourceChannelCommand,PollSourceChannelsCommand}.php`
- `app/Filament/Resources/SourceChannels/...` (Resource, Pages, Schemas, Tables — за шаблоном `ContentProjects`)
- `tests/Support/QueuedLlmManager.php` + тести під кожен компонент

Modify: `app/Models/Video.php`, `app/Jobs/RenderVideoJob.php`, `bootstrap/app.php` (scheduler), `bootstrap/providers.php` (реєстрація `SourceServiceProvider`), `docker/worker/Dockerfile` (не стейджити), Filament/console місця, що припускають ненульові `contentIdea`/`script` (Task 7), `docs/architecture.md`, `CLAUDE.md`, `docs/GUIDE.md`, `ROADMAP.md`.

---

### Task 1: Схема даних, моделі, enum-и, фабрики, конфіг

**Files:**
- Create: 4 міграції (див. вище), 3 моделі, 3 enum-и, 3 фабрики, `config/clips.php`
- Modify: `app/Models/Video.php` (fillable `source_clip_id`, зв'язок `sourceClip()`)
- Test: `tests/Feature/Models/SourceModelsTest.php`

**Interfaces:**
- Produces (використовують усі наступні задачі):
  - `App\Models\Enums\SourceChannelMode` (`Whole='whole'`, `Fixed='fixed'`, `Highlights='highlights'`), `SourceChannelFraming` (`BlurPad='blur_pad'`, `Crop='crop'`), `SourceVideoStatus` (`Discovered, Downloading, Downloaded, Transcribed, ClipsSelected, ClipsCreated, Skipped, NoClips, Failed` зі значеннями `discovered|downloading|downloaded|transcribed|clips_selected|clips_created|skipped|no_clips|failed`).
  - `SourceChannel`: fillable `content_project_id,url,name,mode,target_seconds,tolerance_seconds,max_clips,min_score,max_source_minutes,framing,is_active,last_checked_at`; casts `mode`→enum, `framing`→enum, `is_active`→bool, `last_checked_at`→datetime; зв'язки `contentProject()`, `sourceVideos()`.
  - `SourceVideo`: fillable `source_channel_id,youtube_id,title,duration,file_path,transcript,status,failed_stage,error_message`; casts `duration`→float, `transcript`→array, `status`→enum; зв'язки `sourceChannel()`, `clips()` (HasMany `SourceClip`).
  - `SourceClip`: fillable `source_video_id,start,end,title,hook,score,reason,video_id`; casts `start,end`→float; зв'язки `sourceVideo()`, `video()`.
  - `Video::sourceClip()` BelongsTo; `videos.source_clip_id`, `content_idea_id`/`script_id` nullable.
  - `config('clips.*')`: `poll_interval_minutes`=30, `discover_limit`=5, `window_minutes`=90, `window_overlap_seconds`=60, `utterance_max_seconds`=15.0, `utterance_pause_seconds`=0.7, `padding_seconds`=0.15, `subtitle_max_words`=6, `yt_dlp_binary`='yt-dlp', `yt_dlp_format`='bv*[height<=1080]+ba/b[height<=1080]', `yt_dlp_cookies_file`=null, `yt_dlp_timeout`=3600.

- [ ] **Step 1: Failing test.** `SourceModelsTest`: (a) фабрики створюють канал → відео → кліп, зв'язки працюють, enum-касти повертають enum; (b) `Video::factory()->create(['content_idea_id' => null, 'script_id' => null, 'source_clip_id' => $clip->id])` успішно, `$video->sourceClip` == кліп; (c) дубль `youtube_id` кидає `QueryException`.
- [ ] **Step 2:** `php artisan test --filter=SourceModelsTest` → FAIL (класів нема).
- [ ] **Step 3: Міграції.** `source_channels` (`id`, `foreignId content_project_id->constrained()->cascadeOnDelete()`, `string url`, `string name nullable`, `string mode default 'highlights'`, `unsignedSmallInteger target_seconds default 60`, `unsignedSmallInteger tolerance_seconds default 15`, `unsignedSmallInteger max_clips default 3`, `unsignedTinyInteger min_score default 6`, `unsignedSmallInteger max_source_minutes default 120`, `string framing default 'blur_pad'`, `boolean is_active default true`, `timestamp last_checked_at nullable`, timestamps). `source_videos` (`id`, `foreignId source_channel_id->constrained()->cascadeOnDelete()`, `string youtube_id unique`, `string title`, `double duration default 0`, `string file_path nullable`, `json transcript nullable`, `string status default 'discovered'`, `string failed_stage nullable`, `text error_message nullable`, timestamps, index на `status`). `source_clips` (`id`, `foreignId source_video_id->constrained()->cascadeOnDelete()`, `double start`, `double end`, `string title`, `text hook nullable`, `unsignedTinyInteger score nullable`, `text reason nullable`, `foreignId video_id nullable->constrained()->nullOnDelete()`, timestamps). `add_source_clip_id_to_videos_table`: `foreignId('source_clip_id')->nullable()->constrained()->nullOnDelete()`, та `$table->unsignedBigInteger('content_idea_id')->nullable()->change(); $table->unsignedBigInteger('script_id')->nullable()->change();` (з `down()`, що повертає NOT NULL лише якщо нема null-рядків — достатньо простого `->nullable(false)->change()`).
- [ ] **Step 4: Enum-и, моделі, фабрики** за наведеним контрактом (фабрики: `SourceChannelFactory` — `content_project_id => ContentProject::factory()`, `url => 'https://www.youtube.com/@'.fake()->userName()`, `mode => SourceChannelMode::Highlights`; `SourceVideoFactory` — `source_channel_id => SourceChannel::factory()`, `youtube_id => fake()->unique()->regexify('[A-Za-z0-9_-]{11}')`, `title`, `duration => 600`, `status => SourceVideoStatus::Discovered`; `SourceClipFactory` — `source_video_id => SourceVideo::factory()`, `start => 10.0`, `end => 70.0`, `title`). Моделі мають `use HasFactory`. `config/clips.php` з ключами вище (env-параметри `CLIPS_*`, `YT_DLP_*`).
- [ ] **Step 5:** `php artisan test --filter=SourceModelsTest` → PASS; `php artisan test --filter=VideoDomainModelsTest` теж PASS (регресія).
- [ ] **Step 6: Commit** (`git add` лише явні файли): `feat(source): add source channel/video/clip schema and models`.

---

### Task 2: Чисті хелпери — `Utterance`, `UtteranceBuilder`, `TranscriptPromptFormatter`, `SubtitleSlicer`

**Files:**
- Create: `app/Domain/Source/Support/{Utterance,UtteranceBuilder,TranscriptPromptFormatter,SubtitleSlicer}.php`
- Test: `tests/Unit/Domain/Source/{UtteranceBuilderTest,TranscriptPromptFormatterTest,SubtitleSlicerTest}.php` (`PHPUnit\Framework\TestCase`)

**Interfaces (Produces):**
```php
namespace App\Domain\Source\Support;

final class Utterance {
    public function __construct(public readonly int $id, public readonly float $start, public readonly float $end, public readonly string $text) {}
    public function duration(): float;                       // end - start
    /** @return array{id:int,start:float,end:float,text:string} */ public function toArray(): array;
    /** @param array{id:int,start:float|int,end:float|int,text:string} $data */ public static function fromArray(array $data): self;
}
final class UtteranceBuilder {
    public function __construct(private readonly float $pauseSeconds = 0.7, private readonly float $maxSeconds = 15.0) {}
    /** @param array<int,array{start:float,end:float,text:string}> $segments @return list<Utterance> */
    public function build(array $segments): array;
}
final class TranscriptPromptFormatter {
    /** @param list<Utterance> $utterances */ public function format(array $utterances): string;
}
final class SubtitleSlicer {
    public function __construct(private readonly int $maxWords = 6) {}
    /** @param array<int,array{start:float,end:float,text:string}> $segments
     *  @return array<int,array{start:float,end:float,text:string}> сегменти в межах [clipStart, clipEnd], зсунуті на -clipStart, довгі поділені на чанки ≤maxWords слів */
    public function slice(array $segments, float $clipStart, float $clipEnd): array;
}
```

- [ ] **Step 1: Failing tests.**
  - `UtteranceBuilderTest`: (a) сегменти `0–2 "So the real"`, `2–4 "problem is this."` → 1 репліка `0..4`, текст `"So the real problem is this."`, `id=1`; (b) `0–2 "Hello there"`, `3–5 "again friend"` (пауза 1 с > 0,7) → 2 репліки з id 1 і 2; (c) `new UtteranceBuilder(0.7, 10.0)` + три суцільні сегменти по 6 с без пунктуації (`0–6`,`6–12`,`12–18`) → репліки `[0–12]`, `[12–18]`; (d) сегмент з порожнім/пробільним текстом ігнорується; (e) закриваюча лапка після крапки (`He said "stop."`) закриває репліку.
  - `TranscriptPromptFormatterTest`: репліка `id=12,start=221.4,end=227.1,text="So the real problem."` → рядок рівно `[12] 03:41–03:47 (6s) So the real problem.`; кілька реплік — рядки через `\n`; час ≥ 1 год виводиться як `75:10` (хвилини не обрізаються по 59).
  - `SubtitleSlicerTest`: (a) сегмент `100–102 "Hi there"`, кліп `95–120` → `[['start'=>5.0,'end'=>7.0,'text'=>'Hi there']]`; (b) сегмент поза кліпом відкидається; (c) сегмент `10–16` з 8 слів при `maxWords=6`, кліп `10–20` → 2 чанки: `0.0–4.5` (6 слів) і `4.5–6.0` (2 слова) (`assertEqualsWithDelta`); (d) сегмент, що перетинає межу, обрізається по часу до межі кліпа.
- [ ] **Step 2:** `php artisan test --filter='UtteranceBuilderTest|TranscriptPromptFormatterTest|SubtitleSlicerTest'` → FAIL.
- [ ] **Step 3: Реалізація.**
```php
// UtteranceBuilder::build
$utterances = []; $id = 1; $start = null; $end = 0.0; $parts = [];
$flush = function () use (&$utterances, &$id, &$start, &$end, &$parts): void {
    if ($parts === []) { return; }
    $utterances[] = new Utterance($id++, (float) $start, (float) $end, implode(' ', $parts));
    $start = null; $parts = [];
};
foreach ($segments as $segment) {
    $text = trim((string) $segment['text']);
    if ($text === '') { continue; }
    if ($parts !== [] && ((float) $segment['start'] - $end) > $this->pauseSeconds) { $flush(); }
    $start ??= (float) $segment['start'];
    $end = (float) $segment['end'];
    $parts[] = $text;
    if (preg_match('/[.!?…]["\'”»)\]]*$/u', $text) === 1 || ($end - $start) >= $this->maxSeconds) { $flush(); }
}
$flush();
return $utterances;
```
```php
// TranscriptPromptFormatter
public function format(array $utterances): string {
    return implode("\n", array_map(fn (Utterance $u): string => sprintf(
        '[%d] %s–%s (%ds) %s', $u->id, $this->clock($u->start), $this->clock($u->end), (int) round($u->duration()), $u->text,
    ), $utterances));
}
private function clock(float $seconds): string { $t = (int) floor($seconds); return sprintf('%02d:%02d', intdiv($t, 60), $t % 60); }
```
```php
// SubtitleSlicer
public function slice(array $segments, float $clipStart, float $clipEnd): array {
    $out = [];
    foreach ($segments as $s) {
        if ($s['end'] <= $clipStart || $s['start'] >= $clipEnd) { continue; }
        $start = max((float) $s['start'], $clipStart); $end = min((float) $s['end'], $clipEnd);
        foreach ($this->chunk((string) $s['text'], $start, $end) as $c) {
            $out[] = ['start' => round($c['start'] - $clipStart, 3), 'end' => round($c['end'] - $clipStart, 3), 'text' => $c['text']];
        }
    }
    return $out;
}
private function chunk(string $text, float $start, float $end): array {
    $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($words === []) { return []; }
    $total = count($words); $span = $end - $start; $cursor = $start; $done = 0; $chunks = [];
    foreach (array_chunk($words, $this->maxWords) as $group) {
        $done += count($group); $chunkEnd = $start + $span * $done / $total;
        $chunks[] = ['start' => $cursor, 'end' => $chunkEnd, 'text' => implode(' ', $group)];
        $cursor = $chunkEnd;
    }
    return $chunks;
}
```
`Utterance::fromArray` кастить id до int, start/end до float.
- [ ] **Step 4:** ті ж тести → PASS.
- [ ] **Step 5: Commit:** `feat(source): add utterance builder, prompt formatter and subtitle slicer`.

---

### Task 3: `Clip`, `ClipConstraints`, `ClipValidator`, `ClipMerger`, `TranscriptWindower`, винятки

**Files:**
- Create: `app/Domain/Source/Support/{Clip,ClipConstraints,ClipValidator,ClipMerger,TranscriptWindower}.php`, `app/Domain/Source/Exceptions/{InvalidClipSelectionException,ClipSelectionFailedException}.php`
- Test: `tests/Unit/Domain/Source/{ClipValidatorTest,ClipMergerTest,TranscriptWindowerTest}.php`

**Interfaces:**
- Consumes: `Utterance` (Task 2), `SourceChannelMode`, `SourceChannel` (Task 1).
- Produces:
```php
namespace App\Domain\Source\Exceptions;
final class InvalidClipSelectionException extends \InvalidArgumentException {}
final class ClipSelectionFailedException extends \RuntimeException {}

namespace App\Domain\Source\Support;
final class Clip {
    public function __construct(public readonly float $start, public readonly float $end, public readonly string $title,
        public readonly ?string $hook = null, public readonly ?int $score = null, public readonly ?string $reason = null) {}
    public function duration(): float;
}
final class ClipConstraints {
    public function __construct(public readonly SourceChannelMode $mode, public readonly int $targetSeconds, public readonly int $toleranceSeconds,
        public readonly int $maxClips, public readonly int $minScore, public readonly int $minTailSeconds = 10) {}
    public static function fromChannel(SourceChannel $channel): self;
    public function minSeconds(): int;   // max(1, target - tolerance)
    public function maxSeconds(): int;   // target + tolerance
}
final class ClipValidator {
    public function __construct(private readonly float $paddingSeconds = 0.15) {}
    /** @param array<int,array<string,mixed>> $rawClips  елементи {start_id,end_id,title,hook,score,reason}
     *  @param list<Utterance> $utterances @return list<Clip> впорядковані за start
     *  @throws InvalidClipSelectionException */
    public function validate(array $rawClips, array $utterances, ClipConstraints $constraints, bool $finalWindow = true): array;
}
final class ClipMerger {
    /** @param list<Clip> $clips @return list<Clip> */
    public function merge(array $clips, ClipConstraints $constraints): array;
}
final class TranscriptWindower {
    /** @param list<Utterance> $utterances @return list<list<Utterance>> */
    public function windows(array $utterances, float $windowSeconds, float $overlapSeconds): array;
}
```

Правила `ClipValidator::validate` (у такому порядку):
1. Для кожного елемента: `start_id`/`end_id` — цілі й існують у `$utterances` (інакше `InvalidClipSelectionException("clip #N references unknown utterance id X.")` / `"clip #N is missing integer [start_id]."`), позиція `start` ≤ позиції `end` (інакше `"clip #N has start_id after end_id."`).
2. Сортування за позицією `start`; перетин (`start_pos ≤ prev end_pos`) → `"clips #A and #B overlap."`.
3. Тривалість = `last.end − first.start` (без padding). `> maxSeconds + 0.5` → виняток `"clip #N lasts 95.0s but must be between 45 and 75 seconds."`. `< minSeconds − 0.5` → виняток такого ж формату, **крім** хвоста: у режимі `Fixed` при `$finalWindow === true` останній кліп може бути коротшим, якщо ≥ `minTailSeconds`; якщо коротший — мовчки відкидається.
4. `Highlights`: `score` обов'язковий цілий (інакше виняток), лишаються `score ≥ minScore`, потім топ-`maxClips` за score, знову за часом. `Fixed`: score не вимагається.
5. Padding: `start = min(first.start, max(first.start − pad, prev? prev.end : 0.0))`, `end = max(last.end, min(last.end + pad, next? next.start : last.end + pad))`, де prev/next — сусідні репліки в повному списку. `title` — trim, порожній → перші 60 символів тексту першої репліки (`mb_substr`).

`ClipMerger::merge`: `Highlights` — сортує за score desc (null=0), жадібно приймає кліпи, що не перетинаються за часом (`a.start < b.end && a.end > b.start` = перетин) з уже прийнятими, обрізає до `maxClips`, повертає за `start`. `Fixed`/`Whole` — сортує за `start`, приймає без перетинів (лишається раніший).

`TranscriptWindower::windows`: якщо `last.end − first.start ≤ windowSeconds` → `[all]`; `windowSeconds ≤ overlapSeconds` → `InvalidArgumentException`. Інакше: `windowStart = first.start`; цикл `while windowStart ≤ last.start`: chunk = репліки зі `start ∈ [windowStart, windowStart+windowSeconds)`; непорожній chunk додається; якщо chunk містить останню репліку → break; `windowStart += windowSeconds − overlapSeconds`.

- [ ] **Step 1: Failing tests.** У `ClipValidatorTest` хелпер `utterances(array $lengths, float $gap = 0.5): array` (репліки з id 1..N, `start` накопичується: `start_i = сума попередніх (len+gap)`, `end = start+len`). Сценарії (кожен — окремий тест):
  - padded: `utterances([10,10,10,10,10,10,10,10,10,10,10,10])`, highlights (target 60, tol 15, max 3, min 6), кліп `{start_id:2,end_id:7,...score:8}` → один `Clip`, `start≈10.35`, `end≈73.15` (`assertEqualsWithDelta`, 0.001), `score===8`.
  - unknown id → exception; `start_id > end_id` → exception; перетин (`1..6` і `5..10`) → exception; занадто довгий (`utterances([80,80])`, кліп id 1) → exception з підрядком `must be between`; занадто короткий не-хвіст у highlights (`[20,20]`, кліп id 1) → exception; відсутній `score` у highlights → exception.
  - highlights: `utterances([50,50,50,50])`, 4 одно-реплікові кліпи зі score 9,5,8,7, `maxClips=2, minScore=6` → 2 кліпи зі score 9 і 8 у хронологічному порядку.
  - fixed: `[50,50,12]` → 3 кліпи (хвіст 12 ≥ 10 лишається); `[50,50,8]` → 2 кліпи (хвіст відкинуто); `[50,50,12]` з `finalWindow=false` → exception на хвості.
  - `ClipMergerTest`: highlights — два перекривні кліпи зі score 9 і 5 → лишається 9-й; ліміт `maxClips`; fixed — перекривні → лишається раніший.
  - `TranscriptWindowerTest`: 10 реплік по 10 с з кроком 10 (`start=0,10,…,90`): `windows(..., 40, 10)` → 3 вікна з id `[1..4]`, `[4..7]`, `[7..10]`; коротке відео → одне вікно; `windowSeconds ≤ overlap` → `InvalidArgumentException`.
- [ ] **Step 2:** `php artisan test --filter='ClipValidatorTest|ClipMergerTest|TranscriptWindowerTest'` → FAIL.
- [ ] **Step 3: Реалізувати за правилами вище.** `ClipConstraints::fromChannel` бере `mode,target_seconds,tolerance_seconds,max_clips,min_score`.
- [ ] **Step 4:** тести → PASS.
- [ ] **Step 5: Commit:** `feat(source): add clip validator, merger and transcript windower`.

---

### Task 4: `ClipSelector` (LLM) + тестовий `QueuedLlmManager`

**Files:**
- Create: `app/Domain/Source/Services/ClipSelector.php`, `tests/Support/QueuedLlmManager.php`
- Test: `tests/Feature/Domain/Source/ClipSelectorTest.php`

**Interfaces:**
- Consumes: `LlmManagerInterface::complete(?ContentProject $project, string $purpose, array $messages, ?array $responseSchema, ?string $providerOverride, ?string $modelOverride, float $temperature, ?int $maxTokens): LlmResponse`; Task 2/3 класи; `SourceChannel`, `SourceVideo`.
- Produces:
```php
final class ClipSelector {
    public function __construct(LlmManagerInterface $llmManager, TranscriptPromptFormatter $formatter,
        ClipValidator $validator, ClipMerger $merger, TranscriptWindower $windower) {}
    /** @param list<Utterance> $utterances @return list<Clip>
     *  @throws ClipSelectionFailedException */
    public function select(SourceChannel $channel, SourceVideo $video, array $utterances): array;
}
```
- `tests/Support/QueuedLlmManager` (namespace `Tests\Support`): `implements LlmManagerInterface`; `__construct(array $responses)`; `public array $captured = []` (масив `messages` кожного виклику), `public array $purposes = []`; `complete()` зсуває наступну відповідь із черги (порожня черга → `RuntimeException`) і повертає `new LlmResponse($content, 'fake', 'fake-model', 10, 10)`; `resolve()` повертає `new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model')`.

Поведінка `select`:
- `mode === Whole` → `[new Clip(0.0, (float) $video->duration, $video->title)]`, LLM не викликається.
- `$utterances === []` → `[]`.
- Інакше: `windows = windower->windows($utterances, config('clips.window_minutes')*60, config('clips.window_overlap_seconds'))`; для кожного вікна `selectWindow(...)` (`$finalWindow` = це останнє вікно) → `validator->validate(...)`; результати всіх вікон → `merger->merge(...)`.
- `selectWindow`: повідомлення `[system, user]`; `user` = `formatter->format($windowUtterances)`; виклик `complete(project: $channel->contentProject, purpose: 'clip_selection', messages, responseSchema: schema(), temperature: 0.3)`; парсинг `json_decode(..., true, flags: JSON_THROW_ON_ERROR)`, наявність масиву `clips`; `catch (JsonException|InvalidArgumentException)` → repair (до 2 повторів: додати `assistant` = відповідь і `user` = `"Invalid response: {$error}. Reply again with valid JSON matching the schema exactly."`); після вичерпання — `ClipSelectionFailedException($lastError)`.
- Схема (`name` = `clip_selection`, `strict` = true, за зразком `GenerateScenesService::schema()`): `clips` — масив об'єктів з обов'язковими `start_id:int, end_id:int, title:string, hook:string, score:int, reason:string`, `additionalProperties:false`.
- System-промпти (англійською; містять `target`, `min`, `max`, `maxClips` та інструкцію писати `title/hook/reason` мовою транскрипту):
  - `Fixed`: «You are a video editor. The transcript is a list of numbered utterances: `[id] mm:ss–mm:ss (Ns) text`. Split the video into consecutive clips of about {target}s each (allowed {min}–{max}s: the summed durations of utterances start_id..end_id). Cut only between utterances, at natural topic boundaries; never split a sentence. Clips must not overlap and should cover the transcript in order. For each clip give a short title, a one-sentence hook, an engagement score 1–10 and a brief reason. Write title, hook and reason in the transcript's language. Respond only with JSON matching the schema.»
  - `Highlights`: «…Pick only the genuinely most interesting self-contained moments (a complete thought understandable without the rest of the video: a strong claim, story, insight or emotional peak). Return at most {maxClips} clips — fewer, or none, if fewer are truly good; never pad with weak ones. Each clip lasts {min}–{max}s, starts where a thought begins and ends at a natural conclusion, and clips must not overlap. Score 1–10 how engaging each is… Write title, hook and reason in the transcript's language. Respond only with JSON matching the schema.»

- [ ] **Step 1: Failing tests** (`ClipSelectorTest`, `RefreshDatabase`, реплікі — `Utterance` вручну, канал/відео через фабрики): (a) `whole` → один кліп `0..duration`, `purposes === []` (LLM не викликано); (b) `highlights` валідна відповідь → `Clip[]` з правильними секундами й `purposes === ['clip_selection']`, а `captured[0][1]['content']` містить `[3] ` рядок транскрипту; (c) перша відповідь із невідомим id, друга валідна → повертає кліпи, `captured` містить повідомлення `Invalid response:`; (d) три невалідні поспіль → `ClipSelectionFailedException`; (e) порожній список реплік → `[]` без LLM; (f) довге відео (`config(['clips.window_minutes' => 1, 'clips.window_overlap_seconds' => 10])` + реплікі на ~3 хв) → LLM викликається кілька разів, результат без перетинів; (g) system-промпт `fixed` містить `target`-секунди й слово `consecutive`.
- [ ] **Step 2:** FAIL. **Step 3:** реалізація. **Step 4:** PASS.
- [ ] **Step 5:** переконайся, що `purpose 'clip_selection'` працює з `LlmManager::resolve()` (він падає на `default`-налаштування — змін не треба; якщо десь є allow-list purpose — додай).
- [ ] **Step 6: Commit:** `feat(source): add LLM-based ClipSelector`.

---

### Task 5: Завантажувач YouTube (`YoutubeDownloaderInterface`, `YtDlpDownloader`, Fake, провайдер, Dockerfile)

**Files:**
- Create: `app/Domain/Source/YoutubeDownloaderInterface.php`, `app/Domain/Source/Providers/{YtDlpDownloader,FakeYoutubeDownloader}.php`, `app/Providers/SourceServiceProvider.php`
- Modify: `bootstrap/providers.php` (додати `SourceServiceProvider`), `docker/worker/Dockerfile` (**не стейджити**)
- Test: `tests/Unit/Domain/Source/YtDlpDownloaderTest.php` (`Tests\TestCase`, `Process::fake`)

**Interfaces (Produces):**
```php
namespace App\Domain\Source;
interface YoutubeDownloaderInterface {
    /** @return list<array{id:string,title:string,duration:float}> найновіші першими; duration=0.0 якщо невідомо */
    public function listRecent(string $channelUrl, int $limit): array;
    /** Завантажує відео в $destinationPath (mp4). @throws \RuntimeException */
    public function download(string $youtubeId, string $destinationPath): void;
    public function channelName(string $channelUrl): ?string;
}
```
`FakeYoutubeDownloader`: `respondWithVideos(array $videos): static`, `respondWithChannelName(?string): static`, `public array $downloaded = []`, `failDownloadsWith(?string $message): static` (кидає `RuntimeException`); `download()` пише у `$destinationPath` байти `'fake-source-video'` і додає `$youtubeId` у `$downloaded`.

`YtDlpDownloader` (через `Process`, як `WhisperCliTranscriptionProvider`; кожна команда починається з `config('clips.yt_dlp_binary')`; якщо `config('clips.yt_dlp_cookies_file')` не null — додає `--cookies <file>`):
- `listRecent`: URL нормалізується — якщо це `@handle`/`/channel/`/`/c/`/`/user/` URL без `/videos` наприкінці, додається `/videos` (щоб не брати Shorts/Live); команда `[bin, '--flat-playlist', '--playlist-end', (string)$limit, '--print', '%(id)s|||%(title)s|||%(duration)s', $url]`; вивід парситься по рядках `explode('|||', $line, 3)`, `NA`/порожнє → `0.0`; ненульовий exit → `RuntimeException`.
- `download`: `[bin, '-f', config('clips.yt_dlp_format'), '--merge-output-format', 'mp4', '--no-playlist', '--no-progress', '-o', $destinationPath, 'https://www.youtube.com/watch?v='.$id]`, `Process::timeout(config('clips.yt_dlp_timeout'))`; fail → `RuntimeException('yt-dlp download failed: ...')`.
- `channelName`: `[bin, '--flat-playlist', '--playlist-items', '1', '--print', '%(playlist_channel,playlist_uploader)s', $url]`; будь-яка помилка або `NA`/порожньо → `null`.

`SourceServiceProvider::register()`: `bind(YoutubeDownloaderInterface::class, YtDlpDownloader::class)`.

- [ ] **Step 1: Failing tests:** `listRecent` парсить вивід `"abc123|||Title one|||125\nxyz789|||Title two|||NA\n"` у два елементи (`duration` 125.0 і 0.0) і викликає команду з `--playlist-end 5` та URL, що закінчується `/videos` для `https://www.youtube.com/@foo` (не змінює вже `.../videos`); ненульовий exit → `RuntimeException`; `download` викликає команду з `-f`, `--merge-output-format mp4`, шляхом призначення й `watch?v=abc123`; помилка → `RuntimeException`; `channelName` повертає trimmed назву, а `NA` → `null`; cookies-файл додає `--cookies`. Перевірка аргументів — `Process::assertRan(fn ($p) => in_array('--playlist-end', $p->command, true) && ...)`. Окремо `FakeYoutubeDownloader` (запис файлу + fail-режим). Контейнер: `app(YoutubeDownloaderInterface::class)` → `YtDlpDownloader`.
- [ ] **Step 2:** FAIL. **Step 3:** реалізація. **Step 4:** PASS.
- [ ] **Step 5: Dockerfile.** У `docker/worker/Dockerfile` у рядку `pip3 install ... faster-whisper` додай `yt-dlp` (`... faster-whisper yt-dlp`). Файл має чужі незакомічені зміни — **не** `git add` його; зафіксуй у звіті, що зміну треба закомітити власникові.
- [ ] **Step 6: Commit** (без Dockerfile): `feat(source): add YouTube downloader abstraction with yt-dlp and fake`.

---

### Task 6: `SourceClipRenderer`

**Files:**
- Create: `app/Domain/Video/Providers/SourceClipRenderer.php`
- Test: `tests/Unit/Domain/Video/SourceClipRendererTest.php` (за технікою `FfmpegVideoRendererTest::fakeFfmpegProcesses` — `Process::fake(callback)`, який для `-show_streams` віддає JSON, а для ffmpeg створює вихідний файл `end($command)`)

**Interfaces:**
- Consumes: `Video` з `sourceClip` (→ `sourceVideo.sourceChannel`), `subtitle` (MediaAsset з `metadata['segments']` уже зсунутими на 0), `AssSubtitleFormatter`, `config('render.*')`.
- Produces: `final class SourceClipRenderer implements VideoRendererInterface` → `render(Video $video): RenderResult` (шлях `projects/{project}/renders/{video}.mp4`, `metadata: ['framing' => ..., 'source_clip_id' => ...]`). Публічних інших методів нема.

Алгоритм `render`:
1. `$clip = $video->sourceClip ?? throw new RuntimeException(...)`; `loadMissing('sourceVideo.sourceChannel')`.
2. workDir `sys_get_temp_dir().'/clip_'.$video->id.'_'.uniqid()`; у `finally` — `File::deleteDirectory`.
3. Джерело копіюється зі storage **потоком**: `$in = $disk->readStream($path)`, `$out = fopen($dest, 'wb')`, `stream_copy_to_stream`; закрити ресурси.
4. `probe($sourcePath)` (ffprobe `-show_format -show_streams`, як у `FfmpegVideoRenderer::probe`) → `duration,width,height,has_audio` (`has_audio` = є stream `codec_type=audio`).
5. ASS: `AssSubtitleFormatter::format($video->subtitle->metadata['segments'] ?? [], [...config('render.subtitles'), 'width' => W, 'height' => H])` → `subtitles.ass`.
6. `$duration = $clip->end - $clip->start`. Команда: `[ffmpeg, '-y', '-ss', (string)$clip->start, '-i', $source]`; якщо `!has_audio` — додати `'-f','lavfi','-i','anullsrc=r=44100:cl=stereo'`; далі `'-t', (string)$duration, '-filter_complex', $filter, '-map','[v]', '-map', has_audio ? '[a]' : '1:a', '-c:v','libx264','-preset','veryfast','-crf','20','-c:a','aac','-pix_fmt','yuv420p','-movflags','+faststart', $output`.
   - Відеофільтр: `blur_pad`: `[0:v]split=2[bg][fg];[bg]scale=W:H:force_original_aspect_ratio=increase,crop=W:H,boxblur=20:5[bgb];[fg]scale=W:H:force_original_aspect_ratio=decrease[fgs];[bgb][fgs]overlay=(W-w)/2:(H-h)/2,setsar=1,fps=F,ass=<escaped>[v]`; `crop`: `[0:v]scale=W:H:force_original_aspect_ratio=increase,crop=W:H,setsar=1,fps=F,ass=<escaped>[v]`. W/H/F з `config('render.resolution.*')`/`config('render.fps')`. Екранування шляху ASS — як `FfmpegVideoRenderer::escapeForFilter` (`\`→`\\`, `:`→`\:`).
   - Аудіо-фільтр при `has_audio`: додається до `filter_complex` через `;[0:a]loudnorm=I=-16:TP=-1.5:LRA=11[a]`.
   - Таймаут: `max((int) config('render.timeout'), (int) ceil($duration * 2))`.
   - Помилка → `RuntimeException('Clip render failed: ...')`.
7. Probe виходу; `$disk->put($storagePath, fopen($output, 'rb'))` (потік); повернути `RenderResult`.

- [ ] **Step 1: Failing tests:** (a) blur_pad: `Process::assertRan` для ffmpeg-команди, що містить `-ss` `10`, `-t` `60`, фільтр із `boxblur` і `overlay`, `loudnorm`, `ass=`; (b) crop: фільтр без `boxblur`; (c) джерело без аудіо: команда містить `anullsrc` і `-map 1:a`, без `loudnorm`; (d) повертає `RenderResult` із `width/height` з probe виходу й записує файл у storage за очікуваним шляхом; (e) без `sourceClip` → `RuntimeException`; (f) ffmpeg фейлиться → `RuntimeException` з `Clip render failed`.
- [ ] **Step 2:** FAIL. **Step 3:** реалізація (без біндингу в контейнері — рендерер резолвиться в `RenderVideoJob` через `app(SourceClipRenderer::class)`). **Step 4:** PASS.
- [ ] **Step 5: Commit:** `feat(video): add SourceClipRenderer for clip cutting with blur-pad and burned subtitles`.

---

### Task 7: `RenderVideoJob` для клипів + null-безпека `Video` без idea/script

**Files:**
- Modify: `app/Jobs/RenderVideoJob.php`, місця з припущенням про ненульові `contentIdea`/`script` (знайти: `grep -rn "contentIdea\|->script\|script_id" app resources/js resources/views --include=*.php --include=*.tsx --include=*.ts`; очікувано Filament `VideosTable`/`VideoForm`, консольні контролери/ресурси Videos, `PipelineJobFailedNotification` тощо)
- Test: `tests/Feature/Jobs/RenderVideoJobTest.php` (розширити наявний, якщо є, або створити поруч у тому ж стилі), `tests/Feature/Console/VideoControllerTest.php` (додати кейс), `tests/Feature/Filament/VideoViewPageTest.php` (додати кейс)

**Interfaces:**
- Consumes: `SourceClipRenderer` (Task 6), `Video::sourceClip()` (Task 1).
- Produces: `RenderVideoJob::handle(VideoRendererInterface $renderer)` — сигнатура **незмінна**; всередині: `if ($video->source_clip_id !== null) { $renderer = app(SourceClipRenderer::class); }`. Guard змінюється на: `status === AssetsReady && subtitle_id !== null && ($video->voiceover !== null || $video->source_clip_id !== null) && $scenesReady`. Eager-load: `Video::with(['scenes.asset', 'voiceover', 'subtitle', 'musicAsset', 'sourceClip.sourceVideo.sourceChannel'])`.

- [ ] **Step 1: Failing tests:** (a) кліп-відео (`source_clip_id` заданий, без `Voiceover`, `AssetsReady`, `subtitle_id` заданий, одна сцена без asset/visual_query) → job викликає **`SourceClipRenderer`** (підмінити його в контейнері на анонімний клас/мок, що повертає `RenderResult`), ставить `Rendered`, диспатчить `QualityCheckVideoJob`; (b) звичайне відео без voiceover, як і раніше, нічого не робить; (c) звичайне відео використовує ін'єктований `VideoRendererInterface` (наявні тести не ламаються); (d) `GET` сторінки списку/перегляду відео в консолі (`VideoControllerTest`) і Filament `VideoViewPageTest` для `Video` з `content_idea_id=null, script_id=null` відповідають 200 (без `Attempt to read property on null`).
- [ ] **Step 2:** FAIL. **Step 3:** зміни job + виправ усі знайдені null-небезпечні місця (оператор `?->`, умовні блоки; поведінку для звичайних відео не змінюй). **Step 4:** `php artisan test --filter='RenderVideoJobTest|VideoControllerTest|VideoViewPageTest|FilamentResourcesTest|VideoRenderActionTest'` → PASS.
- [ ] **Step 5: Commit:** `feat(video): render source clips via SourceClipRenderer and tolerate videos without idea/script`.

---

### Task 8: Jobs — `DiscoverSourceVideosJob`, `DownloadSourceVideoJob`, `TranscribeSourceVideoJob`

**Files:**
- Create: три job-класи
- Test: `tests/Feature/Jobs/{DiscoverSourceVideosJobTest,DownloadSourceVideoJobTest,TranscribeSourceVideoJobTest}.php`

**Interfaces:**
- Consumes: `YoutubeDownloaderInterface`, `TranscriptionProviderInterface`, `UtteranceBuilder` (конструюється з `config('clips.utterance_pause_seconds')`, `config('clips.utterance_max_seconds')`), моделі Task 1, `NotifiesOnPermanentFailure`.
- Produces:
  - `DiscoverSourceVideosJob(public readonly int $channelId)` (черга `default`): бере `listRecent($channel->url, config('clips.discover_limit'))`; для кожного `id`, якого ще нема в `source_videos.youtube_id`, створює `SourceVideo` (`status=Discovered`, `title`, `duration`), збирає нові; оновлює `channel.name` (якщо null → `channelName()`) і `last_checked_at = now()`; для кожного нового — `DownloadSourceVideoJob::dispatch($sv->id)`. Неактивний канал → нічого не робить. `failed()` для Discover лише логує через `Log::channel('video')->error(...)` (канал без `SourceVideo` — не створюй запис).
  - `DownloadSourceVideoJob(public readonly int $sourceVideoId)` (черга `default`, `$timeout = 3700`, `$tries = 2`, `backoff [60, 300]`, `ShouldBeUnique`): пропускає, якщо `status !== Discovered`; якщо `duration > 0 && duration > max_source_minutes*60` → `status=Skipped`, `error_message`='Longer than max_source_minutes' і `return`; `status=Downloading`; `download($youtubeId, $tmp = sys_get_temp_dir().'/src_'.uniqid().'.mp4')`; якщо `duration <= 0` — ffprobe (`config('render.ffprobe_binary')`, `-show_format`, JSON) → оновити `duration` (і повторно перевірити ліміт → `Skipped` + видалити tmp); запис у storage потоком: `$disk->put("source/{$channelId}/{$youtubeId}.mp4", fopen($tmp,'rb'))`; видалити tmp (у `finally`); `file_path`, `status=Downloaded`; `TranscribeSourceVideoJob::dispatch($id)`.
  - `TranscribeSourceVideoJob(public readonly int $sourceVideoId)` (черга `whisper`, `$timeout = 3700`, `$tries = 2`): лише якщо `status === Downloaded`; матеріалізувати джерело потоком у tmp; `ffmpeg -y -i src -vn -ac 1 -ar 16000 audio.wav` (`config('render.ffmpeg_binary')`, `config('render.timeout')` × великий множник: `Process::timeout(3600)`); `transcribe($audioPath, null)` (автовизначення мови); `segments = $result->segments`; `utterances = UtteranceBuilder->build($segments)`; `transcript = ['language' => $result->language, 'segments' => $segments, 'utterances' => array_map(fn ($u) => $u->toArray(), $utterances)]`; якщо `utterances === []` → `status=NoClips`, `error_message='No speech detected'`, **без** dispatch; інакше `status=Transcribed` + `SelectClipsJob::dispatch($id)` (клас створюється в Task 9 — у тесті `Queue::assertPushed(SelectClipsJob::class)` працює лише після Task 9, тому у цій задачі створи мінімальну заглушку класу `SelectClipsJob` із конструктором `(public readonly int $sourceVideoId)` і порожнім `handle(): void {}`; повну реалізацію дасть Task 9).
  - Усі три `failed(Throwable)` (крім Discover) ставлять `status=Failed`, `failed_stage` = `download` / `transcribe`, `error_message`, і `notifyPermanentFailure('video', '<Stage> failed permanently.', ['source_video_id' => ..., 'error' => ...])`.

- [ ] **Step 1: Failing tests** (`Queue::fake()`, `Storage::fake()`, `FakeYoutubeDownloader` і `FakeTranscriptionProvider` підміняються через `$this->app->instance(...)`, `Process::fake` для ffmpeg/ffprobe): Discover — створює лише нові відео (одне вже існує), диспатчить Download для нових, оновлює `last_checked_at`, ім'я каналу; неактивний канал — нічого; Download — успіх (файл у storage, `Downloaded`, dispatch Transcribe), skip за довжиною (`Skipped`, не викликає `download`), невідома тривалість → ffprobe визначає й skip при перевищенні, повторний запуск при `status !== Discovered` — no-op, `failed()` → `Failed` + `failed_stage=download` + `Notification::assertSentTo` (за зразком `GenerateSubtitlesJobTest`); Transcribe — успіх (структура `transcript` з `segments` і `utterances`, dispatch Select), порожній транскрипт → `NoClips` без dispatch, no-op при неправильному статусі, `failed()`.
- [ ] **Step 2:** FAIL. **Step 3:** реалізація. **Step 4:** PASS.
- [ ] **Step 5: Commit:** `feat(source): add discover, download and transcribe jobs`.

---

### Task 9: Jobs — `SelectClipsJob`, `CreateClipVideosJob`

**Files:**
- Modify: `app/Jobs/SelectClipsJob.php` (замінити заглушку з Task 8)
- Create: `app/Jobs/CreateClipVideosJob.php`
- Test: `tests/Feature/Jobs/{SelectClipsJobTest,CreateClipVideosJobTest}.php`

**Interfaces:**
- Consumes: `ClipSelector` (Task 4), `Utterance::fromArray`, `SubtitleSlicer` (`new SubtitleSlicer(config('clips.subtitle_max_words'))`), `SrtFormatter::format`, `MediaAsset`/`MediaAssetType::Subtitle`, `VideoScene`, `VideoSceneType::Broll`, `VideoStatus::AssetsReady`, `RenderVideoJob`.
- Produces:
  - `SelectClipsJob(public readonly int $sourceVideoId)` (черга `default`, `$timeout = 600`, `$tries = 2`): лише `status === Transcribed`; `utterances = array_map(Utterance::fromArray, transcript.utterances)`; `clips = ClipSelector->select($channel, $sourceVideo, $utterances)`; порожньо → `status=NoClips`; інакше у транзакції створює `SourceClip` для кожного (`start,end,title,hook,score,reason`), `status=ClipsSelected`, `CreateClipVideosJob::dispatch($id)`. `failed()` → `Failed`, `failed_stage=select`.
  - `CreateClipVideosJob(public readonly int $sourceVideoId)` (черга `default`): лише `status === ClipsSelected`; для кожного `SourceClip` з `video_id === null` у транзакції: `Video::create([content_project_id => channel.content_project_id, content_idea_id => null, script_id => null, title => clip.title, description => clip.hook, status => AssetsReady, source_clip_id => clip.id, metadata => ['source_video_id' => ..., 'youtube_id' => ..., 'clip_start' => ..., 'clip_end' => ...]])`; `VideoScene::create([video_id, order=0, type=Broll, duration=(int) max(1, round(end-start)), text=title, visual_query=null, asset_id=null])`; сегменти субтитрів = `SubtitleSlicer->slice($sourceVideo->transcript['segments'], $clip->start, $clip->end)`; SRT записується у `projects/{project}/subtitles/{video}.srt`; `MediaAsset::create(['type'=>Subtitle,'provider'=>'whisper','path'=>..., 'mime_type'=>'application/x-subrip','metadata'=>['segments'=>$slice,'language'=>transcript.language],'hash'=>hash('sha256',$srt)])`; `video.subtitle_id`; `clip.video_id`. Після транзакції `RenderVideoJob::dispatch($video->id)`. Після циклу `status=ClipsCreated`. `failed()` → `Failed`, `failed_stage=create`.

- [ ] **Step 1: Failing tests:** Select — успіх (`FakeLlm`/`QueuedLlmManager` підмінений як `LlmManagerInterface` через `$this->app->instance`; створює `SourceClip`, `ClipsSelected`, dispatch Create), порожній вибір → `NoClips`, `Queue::assertNotPushed`, помилка селектора (`ClipSelectionFailedException` після repair) → після `failed()` `Failed` + `failed_stage=select`; whole-канал не викликає LLM. Create — для двох кліпів створює 2 `Video` (без idea/script, `AssetsReady`, `source_clip_id`), кожне з однією сценою (`duration` = округлена довжина), `subtitle` MediaAsset із зсунутими сегментами (перевірити, що `segments[0].start` = початок мінус `clip.start`), SRT у storage, `Queue::assertPushed(RenderVideoJob::class, 2)`; ідемпотентність — кліп з `video_id` не дублюється; `ClipsCreated`.
- [ ] **Step 2:** FAIL. **Step 3:** реалізація. **Step 4:** PASS. **Step 5: Commit:** `feat(source): add clip selection and clip-video creation jobs`.

---

### Task 10: Команди `source:add`, `source:poll` і scheduler

**Files:**
- Create: `app/Console/Commands/{AddSourceChannelCommand,PollSourceChannelsCommand}.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Console/{AddSourceChannelCommandTest,PollSourceChannelsCommandTest}.php`

**Interfaces:**
- `source:add {url} {--project=} {--mode=highlights} {--target=60} {--tolerance=15} {--max-clips=3} {--min-score=6} {--max-source-minutes=120} {--framing=blur_pad}`: `--project` (id `ContentProject`) обов'язковий; невалідні `mode`/`framing`/неіснуючий проєкт → повідомлення про помилку й `self::FAILURE`, запис не створюється; `name` = `YoutubeDownloaderInterface::channelName($url)` (виняток → `null`); виводить `Added source channel #ID (mode=...).`
- `source:poll {--channel=}`: диспатчить `DiscoverSourceVideosJob` для кожного активного каналу (або лише `--channel=ID`), виводить `Dispatched N discovery job(s).`
- Scheduler у `bootstrap/app.php`: `$schedule->command('source:poll')->cron('*/'.config('clips.poll_interval_minutes', 30).' * * * *')->withoutOverlapping();`

- [ ] **Step 1: Failing tests** (`Queue::fake()`, `FakeYoutubeDownloader` через `$this->app->instance`, `artisan(...)->expectsOutputToContain(...)->assertExitCode(...)`, за зразком `CreateContentProjectCommandTest`/`CollectMetricsCommandTest`): створення каналу з опціями; помилки валідації; `source:poll` диспатчить лише для `is_active`; `--channel`.
- [ ] **Step 2:** FAIL. **Step 3:** реалізація. **Step 4:** PASS. **Step 5: Commit:** `feat(source): add source:add and source:poll commands with scheduler entry`.

---

### Task 11: Filament-ресурс `SourceChannelResource`

**Files:**
- Create: `app/Filament/Resources/SourceChannels/{SourceChannelResource.php,Pages/{ListSourceChannels,CreateSourceChannel,EditSourceChannel}.php,Schemas/SourceChannelForm.php,Tables/SourceChannelsTable.php}` — за шаблоном повного CRUD-ресурсу `app/Filament/Resources/ContentProjects/*` (Filament 4: `Schema`/`Table` статичні `configure`, `Heroicon`, `navigationGroup`)
- Modify: `tests/Feature/FilamentResourcesTest.php` (додати slug `source-channels` і створення одного рядка в `seedOneRowPerResource`)
- Test: `tests/Feature/Filament/SourceChannelFormTest.php`

**Поведінка:** форма — `content_project_id` (Select з проєктів), `url` (обов'язкове, url), `name` (необов'язкове), `mode` (Select з enum), `target_seconds`, `tolerance_seconds`, `max_clips`, `min_score` (1–10), `max_source_minutes`, `framing` (Select), `is_active` (Toggle); `target/tolerance/max_clips/min_score` видимі лише для `fixed`/`highlights` (`->visible(fn (Get $get) => $get('mode') !== 'whole')`). Таблиця — `name`/`url`, `mode` badge, проєкт, `is_active` icon, `last_checked_at`, лічильник `sourceVideos_count`; навігаційна група `Sources`.

- [ ] **Step 1: Failing tests:** сторінка списку `/admin/source-channels` доступна адміну (додати в `FilamentResourcesTest`); створення каналу через Livewire-тест форми (`Livewire::test(CreateSourceChannel::class)->fillForm([...])->call('create')->assertHasNoFormErrors()` + запис у БД); валідація: порожній `url` → помилка.
- [ ] **Step 2:** FAIL. **Step 3:** реалізація. **Step 4:** PASS (`--filter='FilamentResourcesTest|SourceChannelFormTest'`). **Step 5: Commit:** `feat(source): add Filament resource for source channels`.

---

### Task 12: Документація, повна перевірка

**Files:**
- Modify: `docs/architecture.md` (новий bounded context `Source`, потік Discover→Download→Transcribe→Select→Create→Render у розділі 2 з mermaid, `SourceClipRenderer` у провайдерах), `CLAUDE.md` (додати `Source` до `app/Domain/...`, згадку про `source:add`/`source:poll`, yt-dlp у worker, короткий опис можливості), `docs/GUIDE.md` (як додати канал, режими нарізки, `WHISPER_TIMEOUT` для довгих відео, обмеження `whole`), `ROADMAP.md` (Phase 10 статус), `docs/superpowers/specs/2026-09-29-phase10-youtube-channel-clips-design.md` (одне уточнення: хвіст у `fixed`, коротший за 10 с, **відкидається**, а не приєднується до попереднього; сегменти субтитрів беруться з `transcript.segments`, поділені `SubtitleSlicer`).
- [ ] **Step 1:** оновити документи.
- [ ] **Step 2:** `php artisan test` — весь набір має бути зеленим; `vendor/bin/pint --test` — чисто для змінених/нових файлів (якщо `pint --test` зачіпає чужі файли з незакомічених змін — не форматуй їх).
- [ ] **Step 3: Commit:** `docs: document Phase 10 YouTube channel clips`.

---

## Self-Review (виконано автором плану)

- **Покриття spec:** режими `whole/fixed/highlights` — Task 3/4/9; дані — Task 1; транскрипт/репліки — Task 2/8; вибір кліпів, вікна, repair — Task 3/4; jobs — Task 8/9; yt-dlp і Fake — Task 5; рендерер, blur-pad/crop, аудіо, ASS — Task 6; `RenderVideoJob`-розгалуження і quality-check без змін (одна сцена) — Task 7/9; керування каналами (Filament + artisan) — Task 10/11; конфіг — Task 1; помилки/no_clips/skipped — Task 8/9; Dockerfile — Task 5; docs — Task 12.
- **Відхилення від spec, зафіксовані в плані:** хвіст `fixed` < 10 с відкидається (а не приєднується); субтитри беруться з `transcript.segments` (короткі сегменти), а не з реплік, і діляться на чанки ≤ `subtitle_max_words`; `duration` `VideoScene` — ціле (колонка `unsignedInteger`), допуск quality-check 2 с покриває округлення.
- **Узгодженість типів:** `Utterance`/`Clip`/`ClipConstraints`/`ClipValidator::validate(array,array,ClipConstraints,bool)`/`ClipMerger::merge(array,ClipConstraints)`/`TranscriptWindower::windows(array,float,float)`/`ClipSelector::select(SourceChannel,SourceVideo,array)` однакові в Task 3, 4, 9. `SourceVideoStatus`-значення однакові в Task 1, 8, 9. Ключі `transcript`: `language`, `segments`, `utterances` — Task 8 пише, Task 9 читає.
