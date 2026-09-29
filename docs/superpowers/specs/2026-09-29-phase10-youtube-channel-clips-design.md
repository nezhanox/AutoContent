# Phase 10 — YouTube-канали → нарізка кліпів із субтитрами (design)

Дата: 2026-09-29
Статус: затверджено користувачем (brainstorming), готово до writing-plans.

## Мета

Нова можливість: користувач додає посилання на YouTube-канал → система
періодично перевіряє нові відео → завантажує їх → транскрибує → або бере
відео цілим, або нарізає на кліпи (LLM вирішує, де різати / що цікаве) →
кожен кліп рендериться у вертикальний 9:16 з **оригінальним звуком** і
вигорілими субтитрами (мова = мова оригіналу) → далі звичайний хвіст
пайплайна: quality check → ручний Approve → Publish.

## Узгоджені рішення

- Права/ліцензія — відповідальність користувача, у системі **немає**
  полів ліцензії чи блокувань.
- Мова субтитрів = мова оригіналу (транскрипція Whisper). Переклад —
  поза скоупом (можлива майбутня фаза).
- Керування каналами у v1 **без** консольного UI: Filament-ресурс +
  artisan-команда `source:add`.
- Клип — це звичайний `Video` з одним `VideoScene`, без `Voiceover`;
  `RenderVideoJob` обирає рендерер за `video.source_clip_id`.
- Для горизонтального джерела за замовчуванням **blur-pad** (розмитий
  збільшений фон + чітке відео по центру); альтернатива `crop`
  (налаштування каналу `framing`).

## Режими нарізки (`SourceChannel.mode`)

| mode | Поведінка |
|---|---|
| `whole` | Ціле відео = один кліп, LLM не викликається. Якщо `duration > max_source_minutes` відео пропускається (`skipped`). |
| `fixed` | Послідовна нарізка шматками ≈ `target_seconds ± tolerance_seconds`. LLM лише підбирає межі за змістом (по репліках), що покривають усе відео. |
| `highlights` | LLM вибирає лише цікаві моменти: не більше `max_clips`, кожен з `score ≥ min_score`, тривалість у `target ± tolerance`. Може повернути менше `max_clips` (навіть 0 → відео `no_clips`). |

## Дані

### `source_channels`
`id`, `content_project_id` (FK), `url`, `name` (nullable, заповнюється з
yt-dlp), `mode` (enum: whole|fixed|highlights), `target_seconds` (default
60), `tolerance_seconds` (default 15), `max_clips` (default 3),
`min_score` (default 6), `max_source_minutes` (default 120), `framing`
(enum: blur_pad|crop, default blur_pad), `is_active` (default true),
`last_checked_at` (nullable), timestamps.

### `source_videos`
`id`, `source_channel_id` (FK, cascade), `youtube_id` (unique),
`title`, `duration` (float, секунди), `file_path` (nullable),
`transcript` (json nullable — репліки, див. нижче), `status` (enum:
discovered|downloading|downloaded|transcribed|clips_selected|
clips_created|skipped|no_clips|failed), `failed_stage` (nullable),
`error_message` (nullable), timestamps.

### `source_clips`
`id`, `source_video_id` (FK, cascade), `start` (float), `end` (float),
`title`, `hook` (nullable), `score` (nullable unsigned tinyint),
`reason` (nullable text), `video_id` (nullable FK → videos, nullOnDelete),
timestamps.

### Зміни в `videos`
- `source_clip_id` — nullable FK → source_clips, nullOnDelete.
- `content_idea_id` і `script_id` → **nullable** (міграція `->change()`);
  unique-індекс на `script_id` лишається (NULL-и не конфліктують).
- Перевірити всі місця, що припускають ненульові `contentIdea`/`script`
  (Filament-ресурс Videos, console-контролери/ресурси, notifications) і
  зробити їх null-безпечними.

## Транскрипт і репліки

`TranscribeSourceVideoJob` витягує аудіо (ffmpeg → wav 16 kHz mono),
запускає `TranscriptionProviderInterface`, потім `UtteranceBuilder`
зливає сегменти Whisper у **репліки**: закриває репліку на завершальній
пунктуації (`. ! ? …`) або паузі > 0,7 с між сегментами, а також якщо
репліка стала довшою за 15 с. Результат зберігається в
`source_videos.transcript`:

```json
{"language": "en", "utterances": [{"id": 12, "start": 221.4, "end": 227.1, "text": "..."}]}
```

## Вибір кліпів (`ClipSelector`)

Ключове правило: **LLM обирає межі за id реплік, а не за секундами**;
секунди бере код із `utterances`.

Промпт-транскрипт (`TranscriptPromptFormatter`):
```
[12] 03:41–03:47 (6с) So the real problem is that nobody teaches this.
```

Схема відповіді (через `LlmManager::complete(... responseSchema ...)`,
`purpose: 'clip_selection'`, витрати йдуть у `LlmUsageLog`):
```json
{"clips": [{"start_id": 12, "end_id": 31, "title": "...", "hook": "...", "score": 8, "reason": "..."}]}
```

Промпт залежить від режиму (`fixed` — «розбий усе відео на послідовні
шматки, що покривають його, різати між репліками»; `highlights` —
«обери лише по-справжньому цікаві моменти, не більше N, з оцінкою
1–10, краще менше, ніж слабкі»).

`ClipValidator` (чиста логіка, без залежностей) перевіряє й нормалізує:
- id існують, `start_id ≤ end_id`;
- кліпи не перетинаються (упорядковуються за `start`);
- тривалість ∈ `[target − tol, target + tol]` (для `fixed` останній
  шматок може бути коротшим за `target − tol`, але не коротшим за 10 с;
  коротший хвіст приєднується до попереднього, якщо вкладається в
  `target + tol`, інакше відкидається);
- `score ≥ min_score`, кількість ≤ `max_clips` (для `highlights`; при
  перевищенні лишаються найвищі за score);
- межі вирівнюються по репліках (start = `utterance.start`, end =
  `utterance.end`), з невеликим padding (0,15 с) що не заходить у сусідні
  репліки.
При невалідній відповіді — repair-цикл як у `GenerateScenesService`
(до 2 повторів із поясненням помилки), потім
`ClipSelectionFailedException`.

Довгі відео: якщо розмір транскрипту > `config('clips.window_minutes')`
(default 90 хв) — `TranscriptWindower` ділить репліки на вікна з
перекриттям 60 с, LLM викликається по кожному, кандидати зливаються,
дублі за перетином прибираються (лишається кращий за score), далі
загальний `ClipValidator`.

## Jobs (ланцюжок, без оркестратора)

```
PollSourceChannelsCommand (scheduler, config clips.poll_interval, default кожні 30 хв)
 → DiscoverSourceVideosJob(channelId)   нові youtube_id → SourceVideo(discovered) і одразу DownloadSourceVideoJob
 → DownloadSourceVideoJob(sourceVideoId)  yt-dlp → storage; skip якщо > max_source_minutes
 → TranscribeSourceVideoJob(sourceVideoId)
 → SelectClipsJob(sourceVideoId)         створює SourceClip[]
 → CreateClipVideosJob(sourceVideoId)    SourceClip → Video + VideoScene + Subtitle, потім RenderVideoJob
```

- Всі jobs використовують `NotifiesOnPermanentFailure`, пишуть
  `SourceVideo.failed_stage` (`discover|download|transcribe|select|create`)
  і `error_message`; черги: download/transcribe → `whisper`/`default`,
  рендер → `render`.
- Ідемпотентність: `youtube_id` unique; повторний Discover не дублює;
  `CreateClipVideosJob` пропускає кліпи з уже наявним `video_id`.
- Перша перевірка каналу **не** створює відео для всієї історії каналу:
  Discover бере лише останні `config('clips.discover_limit')` (default 5)
  відео.

## Інтеграції

- `YoutubeDownloaderInterface` (у `app/Domain/Video/` або новий контекст
  `app/Domain/Source/` — рішення у плані за зручністю, але з тими ж
  конвенціями): `listRecent(string $channelUrl, int $limit): array` →
  `[{id, title, duration}]`, `download(string $youtubeId, string $destPath): void`,
  `channelName(string $url): ?string`.
  Реалізації: `YtDlpDownloader` (через `Process`, як Whisper-провайдер;
  `config/clips.php`: `yt_dlp_binary`, `format` = `bv*[height<=1080]+ba/b[height<=1080]`,
  `merge_output_format` = mp4, `cookies_file` optional, `timeout`),
  `FakeYoutubeDownloader`. Біндинг у `*ServiceProvider`.
- `docker/worker/Dockerfile` (і `docker/php/Dockerfile`, якщо там
  запускається Process): `pip3 install yt-dlp`.
- `SourceClipRenderer implements VideoRendererInterface` (+
  `FakeVideoRenderer` вже підходить):
  1. матеріалізує джерело зі storage у workDir;
  2. `ffmpeg -ss {start} -to {end} -i src ...` з перекодуванням
     (точна нарізка);
  3. кадрування: `blur_pad` = `split[a][b]; [a] scale+crop до 1080x1920,
     boxblur [bg]; [b] scale=1080:-2 [fg]; overlay центр`; `crop` =
     `scale=-2:1920, crop=1080:1920`; вертикальне джерело просто
     scale/pad;
  4. аудіо: оригінальне, `loudnorm`; без voiceover/музики;
  5. ASS з `AssSubtitleFormatter::format(segments, config)` (сегменти —
     репліки/сегменти Whisper у межах кліпа, зрушені на `-start`),
     вигорає `subtitles=` фільтром;
  6. `RenderResult` як у `FfmpegVideoRenderer`, запис у
     `projects/{project}/renders/{video}.mp4`.
- `RenderVideoJob`: якщо `video.source_clip_id !== null` → резолвить
  `SourceClipRenderer`, інакше `VideoRendererInterface` (наявний
  контракт). Eager-load для клипів не вимагає `voiceover/scenes.asset`.
- Quality check: для кліпа один `VideoScene(duration = end − start,
  asset_id = null)`; `FfprobeVideoQualityChecker::expectedDuration()`
  працює без змін. Перевірити, чи є в чекері перевірки, що
  припускають `voiceover`/чорні кадри (blackdetect на blur-pad не має
  спрацьовувати), і адаптувати мінімально.

## Керування каналами (v1)

- Filament-ресурс `SourceChannelResource` (CRUD; поля як у таблиці;
  показує кількість відео/кліпів) — за конвенціями наявних ресурсів.
- `php artisan source:add {url} --project= --mode= --target= --tolerance=
  --max-clips= --min-score=` — створює канал, підтягує `name`.
- `php artisan source:poll` — ручний запуск Discover для всіх активних
  каналів (те саме, що scheduler).

## Конфіг `config/clips.php`

`poll_interval_minutes` (30), `discover_limit` (5), `window_minutes` (90),
`window_overlap_seconds` (60), `utterance_max_seconds` (15),
`utterance_pause_seconds` (0.7), `padding_seconds` (0.15), `yt_dlp_*`.

## Обробка помилок

- yt-dlp падає (видалене/приватне/гео-блок відео, зміна YouTube) →
  `SourceVideo.failed` з `failed_stage=download` і повідомленням; job
  ретраїться згідно `tries/backoff`; Filament показує помилку.
- Відео без мовлення (транскрипт порожній) → `no_clips`.
- LLM повернув сміття після repair-циклу → `failed`, `failed_stage=select`.

## Тестування

За `docs/testing.md`: жодних реальних HTTP/процесів (`Process::fake`,
`FakeYoutubeDownloader`, `FakeTranscriptionProvider`, `FakeLlmProvider`).
Юніт-тести на чисту логіку: `UtteranceBuilder`, `TranscriptPromptFormatter`,
`ClipValidator` (перетини, тривалість, ліміти, вирівнювання, хвіст у
fixed), `TranscriptWindower`, парсинг відповіді + repair-цикл. Feature-
тести: кожен job окремо (стан/статус/dispatch наступного), команди
`source:add`/`source:poll`, `RenderVideoJob` вибір рендерера, побудова
ffmpeg-команди `SourceClipRenderer` (через `Process::fake` перевірити
аргументи: `-ss/-to`, фільтр blur-pad/crop, `loudnorm`, `subtitles=`).
Null-безпека `Video` без `contentIdea/script` у Filament/console.

## Поза скоупом

Консольний UI каналів, переклад субтитрів, автоматичне ліцензування/
перевірка прав, завантаження за прямим лінком одного відео (не каналу),
відстеження уже опублікованих кліпів на платформах, ручний редактор
меж кліпів.
