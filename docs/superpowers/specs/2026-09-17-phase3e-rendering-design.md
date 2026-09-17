# Phase 3e — Rendering: design spec

Джерело: `TechnicalTask.md` (розділи 4, 6.2, 8, 9, 10, 19, 22, 24), `ROADMAP.md` (Phase 3,
частина "VideoRendererInterface + FfmpegVideoRenderer... RenderVideoJob, QualityCheckJob"
і всі три секції "Для Phase 3e — врахувати" з фінальних review 3a/3c/3d).

## Мета

Від `Video` з готовими сценами (asset'и з 3c) і субтитрами (3d) до фінального
`1080x1920.mp4` і базового технічного quality-звіту. П'ята, завершальна під-фаза
Phase 3 (3a Сцени → 3b Voiceover → 3c Assets → 3d Subtitles → **3e Rendering**).
Закриває пункти 8–10 DoD (розділ 24 ТЗ): запуск рендерингу, готовий mp4, перегляд
у Filament.

## Скоуп

Входить:

* `app/Domain/Video/VideoRendererInterface.php` + `RenderResult` DTO (розділ 9 ТЗ,
  сигнатура `render(Video $video): RenderResult` — буквально з ТЗ).
* `app/Domain/Video/Providers/FfmpegVideoRenderer.php` — реальна реалізація через
  `Illuminate\Support\Facades\Process` (той самий підхід, що
  `WhisperCliTranscriptionProvider` у 3d — Laravel-обгортка над Symfony Process,
  не сирий Symfony Process, заради `Process::fake()`-тестованості).
  `app/Domain/Video/Providers/FakeVideoRenderer.php` — тестовий двійник.
* `app/Domain/Video/Support/AssSubtitleFormatter.php` — чиста функція
  `segments[] + style config → .ass`-текст, незалежно тестована (аналог
  `SrtFormatter` з 3d; ASS-стилізація — те, що 3d свідомо відклала сюди).
* `app/Domain/Video/VideoQualityCheckerInterface.php` + `QualityCheckResult` DTO.
* `app/Domain/Video/Providers/FfprobeVideoQualityChecker.php` — мінімальний
  технічний QC через `ffprobe`/`ffmpeg blackdetect` (без LLM — рішення користувача,
  ТЗ DoD не вимагає quality check для MVP; LLM-based QC відкладено).
  `app/Domain/Video/Providers/FakeVideoQualityChecker.php`.
* `app/Providers/RenderServiceProvider.php` — прямий bind (без Manager-шару, той
  самий підхід, що `TranscriptionServiceProvider`/`AssetServiceProvider`):
  `VideoRendererInterface → FfmpegVideoRenderer`,
  `VideoQualityCheckerInterface → FfprobeVideoQualityChecker`.
* `config/render.php`.
* ALTER-міграція на `videos`: `music_asset_id` (nullable FK → `media_assets`,
  `nullOnDelete`), `quality_passed` (nullable boolean), `quality_report` (jsonb
  nullable) — рішення користувача: вибір фонової музики додається саме в 3e.
* `Video::musicAsset(): BelongsTo` relation.
* `app/Jobs/RenderVideoJob.php`, `app/Jobs/QualityCheckVideoJob.php` — idempotent,
  timeout/retry, обидва на виділеній черзі `render`.
* Docker: `docker/worker/Dockerfile` CMD додає чергу `render` (ffmpeg вже є в
  образі з 3d — жодних нових бінарників не треба).
* Filament: row actions "Render Video" і "Check Quality" на `VideosTable`,
  `music_asset_id`-select і read-only quality-поля на `VideoForm`.
* Feature/Unit-тести: рендерер і quality checker (через `Process::fake()`, без
  реального ffmpeg), `AssSubtitleFormatter`, обидва jobs, Filament-дії.

Не входить (свідомо відкладено):

* LLM-based quality check (purpose=`quality_check` через `LlmManager`) — рішення
  користувача під час брейнштормінгу: ТЗ DoD (розділ 24) не вимагає quality check
  для MVP взагалі, тож обрано мінімальний технічний QC через `ffprobe`; LLM-варіант
  можна додати пізніше як другий `VideoQualityCheckerInterface`-провайдер без зміни
  інтерфейсу.
* Об'єднання `worker`/`horizon` в один Dockerfile — рішення користувача: замість
  цього для `RenderVideoJob`/`QualityCheckVideoJob` заведено окрему чергу `render`
  на `worker`, той самий патерн, що `whisper` у 3d. Архітектурне дублювання
  worker/horizon (двоє незалежних консюмерів однієї Redis-інсталяції з різними
  Dockerfile) лишається відкритим для майбутнього пакетного рішення.
* Кілька vertical-шаблонів чи горизонтальний формат — лише один шаблон 1080×1920
  з розділу 10 ТЗ.
* Розрізнення permanent/transient помилок у jobs — наскрізний карі-овер з 3c/3d
  review, стосується вже 5 jobs поспіль; свідомо не закривається точково в 3e
  (сам ROADMAP радить пакетний фікс, не по фазі).
* `Notification::make()->sendToDatabase()` для видимості permanent failure в
  адмінці — той самий наскрізний карі-овер з Phase 2.
* GPU-прискорення ffmpeg (`-hwaccel`) — CPU-only рендеринг для MVP, той самий
  підхід, що `device="cpu"` у Whisper-скрипті 3d.
* Точне налаштування xfade-переходів під кожен тип сцени (`VideoSceneType`) —
  3e реалізує один глобальний конфігурований тип переходу (`config('render.
  transition')`) між усіма сусідніми сценами, без per-scene override.

## Рішення (там, де ТЗ не фіксує деталь явно)

### Побудова відео — багатоетапний pipeline (підхід підтверджений користувачем)

`FfmpegVideoRenderer::render()` виконує послідовність окремих ffmpeg-викликів
через тимчасові файли, а не один `filter_complex` на все — простіше тестувати
(`Process::fake()` рахує виклики і перевіряє аргументи покроково) і дебажити
вручну:

1. **Нормалізація кожної сцени** — для кожної `VideoScene` (worker вже гарантує
   `asset_id !== null` на момент `AssetsReady`, тип визначається з
   `$scene->asset->type`, не з `VideoScene.type`) окремий ffmpeg-виклик масштабує/
   кадрує asset до `config('render.resolution')`, обрізає/зациклює до
   `scene.duration`, без звукової доріжки (`-an`):

```php
private function normalizeScene(VideoScene $scene, Filesystem $disk, string $workDir, int $index): string
{
    $asset = $scene->asset;
    $source = $this->materialize($disk, $asset->path, $workDir, "scene_{$index}_src");
    $output = "{$workDir}/scene_{$index}.mp4";

    [$width, $height] = [config('render.resolution.width'), config('render.resolution.height')];
    $fps = config('render.fps');
    $vf = "scale={$width}:{$height}:force_original_aspect_ratio=decrease,"
        ."pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2,setsar=1,fps={$fps}";

    $isImage = in_array($asset->type, [MediaAssetType::Image, MediaAssetType::Thumbnail], true);

    $command = $isImage
        ? [$this->binary(), '-y', '-loop', '1', '-i', $source, '-t', (string) $scene->duration]
        : [$this->binary(), '-y', '-stream_loop', '-1', '-i', $source, '-t', (string) $scene->duration];

    $command = [...$command, '-vf', $vf, '-r', (string) $fps, '-an', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $output];

    $result = Process::timeout(config('render.timeout'))->run($command);

    if ($result->failed()) {
        throw new RuntimeException("Scene {$index} normalization failed: ".trim($result->errorOutput() ?: $result->output()));
    }

    return $output;
}
```

2. **Конкатенація** — нормалізовані кліпи мають однаковий codec/resolution/fps,
   тож просте склеювання без переходу йде через `concat`-demuxer (`-c copy`,
   без перекодування). Якщо `config('render.transition.type') !== 'none'`
   (дефолт `'fade'`), замість цього використовується ланцюг `xfade`-фільтрів у
   одному `filter_complex` (усі нормалізовані кліпи як входи, кожен наступний
   `xfade` зі зсувом `offset = cumulative_duration - transition_duration`) —
   точний рядок фільтра з динамічною кількістю входів фіксується під час
   імплементації і покривається тестом, що перевіряє структуру filter_complex
   (`Process::fake()` + перевірка аргументів), а не рендерить реальне відео.
3. **ASS-субтитри** — `AssSubtitleFormatter::format($segments, config('render.
   subtitles'))` пише `.ass`-файл у tempDir. Порожній масив `segments` (тихе
   відео, задокументований edge case з review 3d) дає валідний ASS-файл без
   `Dialogue`-рядків — `subtitles`-фільтр ffmpeg просто нічого не малює, це не
   особливий кейс у коді рендерера, а природний наслідок формату ASS.
4. **Фінальний мікс** — один виклик muxить відео (з burn-in субтитрів через
   `ass`-фільтр), voiceover і опційну фонову музику:

```php
private function mixAndBurn(string $videoPath, string $assPath, string $voicePath, ?string $musicPath, float $duration, string $output): void
{
    $inputs = ['-i', $videoPath, '-i', $voicePath];
    if ($musicPath !== null) {
        $inputs = [...$inputs, '-i', $musicPath];
    }

    $voiceVolume = config('render.audio.voice_volume');
    $musicVolume = config('render.audio.music_volume');

    $audioFilter = $musicPath !== null
        ? "[1:a]volume={$voiceVolume}[a1];[2:a]aloop=loop=-1:size=2e9,volume={$musicVolume}[a2];"
            ."[a1][a2]amix=inputs=2:duration=first:dropout_transition=0[a]"
        : "[1:a]volume={$voiceVolume}[a]";

    $filterComplex = "[0:v]ass={$this->escapeForFilter($assPath)}[v];{$audioFilter}";

    $command = [
        $this->binary(), '-y', ...$inputs,
        '-filter_complex', $filterComplex,
        '-map', '[v]', '-map', '[a]',
        '-t', (string) $duration,
        '-c:v', 'libx264', '-c:a', 'aac', '-pix_fmt', 'yuv420p',
        $output,
    ];

    $result = Process::timeout(config('render.timeout'))->run($command);

    if ($result->failed()) {
        throw new RuntimeException('Final render mux failed: '.trim($result->errorOutput() ?: $result->output()));
    }
}
```

   `-t {duration}` = `sum(scene.duration)` — фіксує тривалість фінального
   відео за сценами (узгоджено з narration pacing ще на етапі `GenerateScenesService`
   у 3a), а не за voiceover-доріжкою: якщо voiceover коротший — залишок відео
   грає в тиші (`amix ... duration=first` бере тривалість `[a1]` voiceover, потім
   `-t` все одно ріже фінальний файл по відео); якщо voiceover довший — зайве
   просто відрізається `-t`. Це свідоме рішення, а не помилка синхронізації.

5. **Матеріалізація/очищення** — voiceover, музика і кожен scene-asset
   матеріалізуються у `$workDir` тим самим патерном, що
   `GenerateSubtitlesService` (`Storage::disk(...)->get()` → `file_put_contents`
   на локальний шлях), і весь `$workDir` видаляється в `finally`
   (`File::deleteDirectory($workDir)`) незалежно від успіху.
6. **Проба фінального файлу** — приватний `probe()` викликає `ffprobe -v quiet
   -print_format json -show_format -show_streams` на результуючий mp4, парсить
   `duration`/`width`/`height` для `RenderResult` (той самий бінарник
   перевикористовує `FfprobeVideoQualityChecker`, окремого спільного класу заради
   ~10 рядків parsing-логіки не заводимо — YAGNI).

### `VideoRendererInterface` і DTO

```php
interface VideoRendererInterface
{
    public function render(Video $video): RenderResult;
}
```

```php
final class RenderResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $path,
        public readonly float $duration,
        public readonly int $width,
        public readonly int $height,
        public readonly array $metadata = [],
    ) {}
}
```

### `AssSubtitleFormatter`

```php
final class AssSubtitleFormatter
{
    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $segments
     * @param  array<string, mixed>  $style
     */
    public static function format(array $segments, array $style): string
    {
        $alignment = match ($style['position'] ?? 'bottom') {
            'top' => 8,
            'middle' => 5,
            default => 2,
        };

        $header = "[Script Info]\nScriptType: v4.00+\nPlayResX: {$style['width']}\nPlayResY: {$style['height']}\n\n"
            ."[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, OutlineColour, Alignment, MarginL, MarginR, MarginV\n"
            ."Style: Default,{$style['font']},{$style['font_size']},{$style['primary_colour']},{$style['outline_colour']},"
            ."{$alignment},{$style['margin_h']},{$style['margin_h']},{$style['margin_v']}\n\n"
            ."[Events]\nFormat: Layer, Start, End, Style, Text\n";

        $lines = array_map(
            static fn (array $segment): string => sprintf(
                'Dialogue: 0,%s,%s,Default,%s',
                self::timestamp($segment['start']),
                self::timestamp($segment['end']),
                str_replace(["\r\n", "\n"], '\\N', $segment['text']),
            ),
            $segments,
        );

        return $header.implode("\n", $lines)."\n";
    }

    private static function timestamp(float $seconds): string
    {
        $whole = (int) floor($seconds);

        return sprintf('%d:%02d:%02d.%02d', intdiv($whole, 3600), intdiv($whole % 3600, 60), $whole % 60,
            (int) round(($seconds - $whole) * 100));
    }
}
```

Порожній `$segments` → header без жодного `Dialogue`-рядка — валідний ASS,
`subtitles`/`ass`-фільтр ffmpeg обробляє його без помилок (природне закриття
edge case "тихого" відео з review 3d, без явного if-розгалуження в рендерері).

### `config/render.php`

```php
return [
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
    // Таймаут ОДНОГО ffmpeg/ffprobe-виклику (renderer робить кілька за один render()) —
    // навмисно набагато менший за RenderVideoJob.timeout (900с), інакше один
    // завислий виклик сам вичерпає весь бюджет джоби.
    'timeout' => (int) env('RENDER_TIMEOUT', 180),

    'resolution' => [
        'width' => (int) env('RENDER_WIDTH', 1080),
        'height' => (int) env('RENDER_HEIGHT', 1920),
    ],
    'fps' => (int) env('RENDER_FPS', 30),

    'subtitles' => [
        'font' => env('RENDER_SUBTITLE_FONT', 'DejaVu Sans'),
        'font_size' => (int) env('RENDER_SUBTITLE_FONT_SIZE', 64),
        'position' => env('RENDER_SUBTITLE_POSITION', 'bottom'),
        'margin_v' => (int) env('RENDER_SUBTITLE_MARGIN_V', 120),
        'margin_h' => (int) env('RENDER_SUBTITLE_MARGIN_H', 60),
        'primary_colour' => env('RENDER_SUBTITLE_COLOR', '&H00FFFFFF'),
        'outline_colour' => env('RENDER_SUBTITLE_OUTLINE_COLOR', '&H00000000'),
    ],

    'audio' => [
        'voice_volume' => (float) env('RENDER_VOICE_VOLUME', 1.0),
        'music_volume' => (float) env('RENDER_MUSIC_VOLUME', 0.15),
    ],

    'transition' => [
        'type' => env('RENDER_TRANSITION_TYPE', 'fade'),
        'duration' => (float) env('RENDER_TRANSITION_DURATION', 0.5),
    ],

    'quality_check' => [
        'duration_tolerance' => (float) env('RENDER_QUALITY_DURATION_TOLERANCE', 2.0),
    ],
];
```

`AssSubtitleFormatter::format()` отримує `config('render.subtitles')` злитий з
`width`/`height` з `config('render.resolution')` (для `PlayResX`/`PlayResY`) —
викликач (`FfmpegVideoRenderer`) відповідає за це злиття, сам formatter не читає
config напряму (незалежно тестований, той самий принцип, що `SrtFormatter`).

### `VideoQualityCheckerInterface` і DTO

```php
interface VideoQualityCheckerInterface
{
    public function check(Video $video): QualityCheckResult;
}
```

```php
final class QualityCheckResult
{
    /**
     * @param  array<string, bool>  $checks
     * @param  array<int, string>  $notes
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $passed,
        public readonly array $checks,
        public readonly array $notes = [],
        public readonly array $metadata = [],
    ) {}
}
```

### `FfprobeVideoQualityChecker` — суто технічні перевірки, без LLM

```php
final class FfprobeVideoQualityChecker implements VideoQualityCheckerInterface
{
    public function check(Video $video): QualityCheckResult
    {
        $disk = Storage::disk(config('filesystems.default'));
        $tempPath = tempnam(sys_get_temp_dir(), 'qc_').'.mp4';

        try {
            file_put_contents($tempPath, $disk->get($video->file_path));
            $probe = $this->probe($tempPath);
            $expectedDuration = (float) $video->scenes->sum('duration');

            $checks = [
                'has_video_stream' => $probe['has_video'],
                'has_audio_stream' => $probe['has_audio'],
                'resolution_matches' => $probe['width'] === config('render.resolution.width')
                    && $probe['height'] === config('render.resolution.height'),
                'duration_within_tolerance' => abs($probe['duration'] - $expectedDuration)
                    <= config('render.quality_check.duration_tolerance'),
                'not_excessively_black' => ! $this->hasExcessiveBlackFrames($tempPath),
            ];

            return new QualityCheckResult(
                passed: ! in_array(false, $checks, true),
                checks: $checks,
                metadata: $probe,
            );
        } finally {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    private function hasExcessiveBlackFrames(string $path): bool
    {
        $result = Process::timeout(config('render.timeout'))->run([
            config('render.ffmpeg_binary'), '-i', $path,
            '-vf', 'blackdetect=d=1:pic_th=0.98', '-an', '-f', 'null', '-',
        ]);

        return str_contains($result->errorOutput(), 'black_start');
    }
}
```

`blackdetect` навмисно з жорстким порогом (`d=1` секунда суцільної чорноти,
`pic_th=0.98`) — ловить лише грубі рендер-збої (відсутній asset, чорний кадр на
весь кліп), а не короткі затемнення між сценами. Помилки ffprobe/ffmpeg тут не
звужуються до `\RuntimeException` (на відміну від рендерера) — сирий провал
проби сам по собі є валідним результатом QC (`passed: false`), тому job ловить
його як частину `checks`, а не як exception, що ретраїться.

### Migration: `music_asset_id`, `quality_passed`, `quality_report`

```php
Schema::table('videos', function (Blueprint $table) {
    $table->foreignId('music_asset_id')->nullable()->after('subtitle_id')
        ->constrained('media_assets')->nullOnDelete();
    $table->boolean('quality_passed')->nullable()->after('metadata');
    $table->jsonb('quality_report')->nullable()->after('quality_passed');
});
```

`Video::musicAsset(): BelongsTo` → `MediaAsset` (аналог `subtitle()`). Немає
жодного механізму *автоматичного* підбору музики (пошук за niche/тегами) — це
свідомо мінімальний скоуп: адмін вручну обирає `MediaAsset` (type=Audio) у
`VideoForm`; `music_asset_id` лишається nullable, рендер без музики — валідний
шлях (`musicPath === null` у `mixAndBurn`).

### `RenderVideoJob`

```php
class RenderVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;
    public int $tries = 3;
    public int $uniqueFor = 950;

    public function __construct(public readonly int $videoId)
    {
        $this->onQueue('render');
    }

    public function uniqueId(): string { return (string) $this->videoId; }

    /** @return array<int, int> */
    public function backoff(): array { return [30, 90, 180]; }

    public function handle(VideoRendererInterface $renderer): void
    {
        $video = Video::with(['scenes.asset', 'voiceover', 'subtitle', 'musicAsset'])->findOrFail($this->videoId);

        $scenesReady = $video->scenes->isNotEmpty()
            && $video->scenes->every(fn (VideoScene $scene): bool => $scene->asset_id !== null);

        if ($video->status !== VideoStatus::AssetsReady || $video->subtitle_id === null
            || $video->voiceover === null || ! $scenesReady) {
            return;
        }

        $video->update(['status' => VideoStatus::Rendering]);

        $result = $renderer->render($video);

        DB::transaction(function () use ($video, $result) {
            $video->update([
                'file_path' => $result->path,
                'duration' => (int) round($result->duration),
                'width' => $result->width,
                'height' => $result->height,
                'status' => VideoStatus::Rendered,
            ]);
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('video')->error('Video rendering failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

`timeout=900` — загальний бюджет на **весь** `render()` (нормалізація кожної
сцени + concat/xfade + фінальний мікс + probe, кожен окремий виклик обмежений
`config('render.timeout')=180`с); типове коротке вертикальне відео (кілька
сцен по кілька секунд) вкладається в секунди-десятки секунд сумарно, 900с —
запас на повільний CPU і кілька сцен поспіль, а не очікувана тривалість.

Guard ідентичний паттерну 3a-3d (no-op return, без exception, якщо пайплайн ще
не готовий). `$video->update(['status' => Rendering])` — окремий, поза
транзакцією, до важкого виклику (видимий проміжний стан у Filament під час
рендерингу, той самий принцип, що `VideoStatus::Rendering`/`Rendered` вже
передбачені в enum з Phase 1). **Прийнятий карі-овер**: якщо `renderer->render()`
кине виняток і job вичерпає всі `tries`, `status` лишається `Rendering` назавжди
(row action "Render Video" вимагає `AssetsReady`, отже кнопка ховається без
відновлення без ручного втручання в БД) — той самий наскрізний гап, що вже
задокументований для `GenerateVoiceoverJob`/`CollectVideoAssetsJob`/
`GenerateSubtitlesJob`; свідомо не закривається точково тут (див. "Не входить").

### `QualityCheckVideoJob`

```php
class QualityCheckVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 120;
    public int $tries = 3;
    public int $uniqueFor = 150;

    public function __construct(public readonly int $videoId)
    {
        $this->onQueue('render');
    }

    public function uniqueId(): string { return (string) $this->videoId; }

    /** @return array<int, int> */
    public function backoff(): array { return [10, 30, 60]; }

    public function handle(VideoQualityCheckerInterface $checker): void
    {
        $video = Video::with('scenes')->findOrFail($this->videoId);

        if ($video->status !== VideoStatus::Rendered) {
            return;
        }

        $result = $checker->check($video);

        $video->update([
            'quality_passed' => $result->passed,
            'quality_report' => [
                'checks' => $result->checks,
                'notes' => $result->notes,
                'metadata' => $result->metadata,
            ],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('video')->error('Quality check failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

Немає guard на "вже перевірено" — повторний запуск QC на вже перевіреному відео
навмисно дозволений (ре-запуск після ручного виправлення файлу/конфігу має
оновити звіт, а не бути заблокованим), на відміну від `GenerateSubtitlesJob`
(де другий запуск створив би дублікат `MediaAsset`) — тут це просто
перезаписуваний звіт, ідемпотентність не потребує захисту від дублю.

### Черга `render`, Docker, `REDIS_QUEUE_RETRY_AFTER`

`docker/worker/Dockerfile` — жодних нових залежностей (ffmpeg вже встановлено в
3d), лише команда:

```dockerfile
CMD ["php", "artisan", "queue:work", "--queue=render,whisper,default", "--sleep=3", "--tries=3"]
```

`REDIS_QUEUE_RETRY_AFTER` (`config/queue.php`, `connections.redis.retry_after`)
піднімається з `700` до `950` — має перевищувати найдовший job timeout у
пайплайні (`RenderVideoJob.timeout = 900`), інакше Redis може повторно
видати job іншому worker'у, поки перший ще виконує ffmpeg (той самий ризик,
підняти який ROADMAP явно попереджав з review 3d). `.env.example` отримує
`FFMPEG_BINARY`, `FFPROBE_BINARY`, `RENDER_TIMEOUT`, `RENDER_WIDTH`,
`RENDER_HEIGHT`, `RENDER_FPS`, `RENDER_VOICE_VOLUME`, `RENDER_MUSIC_VOLUME`,
`RENDER_TRANSITION_TYPE`, `RENDER_TRANSITION_DURATION`, оновлений
`REDIS_QUEUE_RETRY_AFTER=950`.

### Filament

`VideosTable.php` — дві нові дії, той самий шаблон, що `generateSubtitles`:

```php
Action::make('renderVideo')
    ->label('Render Video')
    ->visible(fn (Video $record): bool => $record->status === VideoStatus::AssetsReady
        && $record->subtitle_id !== null)
    ->requiresConfirmation()
    ->action(function (Video $record): void {
        RenderVideoJob::dispatch($record->id);
        Notification::make()->title('Rendering queued')->success()->send();
    }),
Action::make('checkQuality')
    ->label('Check Quality')
    ->visible(fn (Video $record): bool => $record->status === VideoStatus::Rendered)
    ->requiresConfirmation()
    ->action(function (Video $record): void {
        QualityCheckVideoJob::dispatch($record->id);
        Notification::make()->title('Quality check queued')->success()->send();
    }),
```

`VideoForm.php` — додається `Select::make('music_asset_id')->relationship('musicAsset',
'path')` (опційне поле, `MediaAsset` type=Audio відфільтровується через
`->relationship(..., modifyQueryUsing: fn ($query) => $query->where('type',
MediaAssetType::Audio))`), плюс read-only показ `quality_passed`
(`Toggle::make(...)->disabled()`) і `quality_report`
(`TextInput::make(...)->disabled()`, той самий підхід, що вже є для `metadata`).

### Тести

* Unit: `AssSubtitleFormatterTest` — коректний ASS-заголовок/стилі, timestamp-
  формат, порожній масив сегментів (валідний ASS без Dialogue), кілька сегментів,
  екранування переносів рядків (`\N`).
* Unit: `FfmpegVideoRendererTest` — через `Process::fake()`: перевірка кількості
  ffmpeg-викликів (по сцені + concat/xfade + фінальний мікс + probe), ключових
  аргументів команд (resolution/fps/duration/volumes з config), обробка сценарію
  без музики (`music_asset_id === null` → без третього input), провал одного з
  кроків кидає `RuntimeException` і не викликає наступні кроки.
* Unit: `FfprobeVideoQualityCheckerTest` — через `Process::fake()`: усі checks
  проходять (happy path), кожен check окремо провалюється (невірна роздільність,
  відсутній аудіо-потік, тривалість поза толерансом, `blackdetect`-вивід), фінальний
  `passed` = AND усіх checks.
* Feature: `RenderVideoJobTest` (з `FakeVideoRenderer`) — happy path (`Video`
  оновлюється: `file_path`/`duration`/`width`/`height`/`status=Rendered`), no-op
  якщо `status !== AssetsReady`, no-op якщо `subtitle_id === null`, no-op якщо
  якась сцена без `asset_id`, статус проміжно виставляється в `Rendering` до
  виклику рендерера.
* Feature: `QualityCheckVideoJobTest` (з `FakeVideoQualityChecker`) — happy path
  (`quality_passed`/`quality_report` заповнюються), no-op якщо `status !==
  Rendered`, повторний запуск оновлює звіт (не блокується).
* Feature: Filament-тест на видимість/dispatch `renderVideo` і `checkQuality`.

## Acceptance criteria (для 3e, закриває пункти 8–10 DoD розділу 24 ТЗ)

* Можна натиснути "Render Video" на `Video` з готовими сценами, voiceover і
  субтитрами й отримати `.mp4`-файл на диску (`Video.file_path` заповнено),
  `Video.status === Rendered`, `duration`/`width`/`height` відповідають
  реальному файлу.
* Готове відео 1080×1920, з випаленими субтитрами (стилізованими за
  `config/render.php`), звуковою доріжкою voiceover (+ опційна фонова музика,
  якщо `music_asset_id` заповнено) — переглядається через посилання у Filament.
* "Check Quality" заповнює `quality_passed`/`quality_report` на основі
  технічних ffprobe-перевірок, без викликів LLM.
* Повторний dispatch `RenderVideoJob` на вже відрендереному відео — no-op
  (guard на `status !== AssetsReady`).
* Жодного реального виклику ffmpeg/ffprobe у тестах — уся сюїта проходить без
  встановленого ffmpeg на машині, де запускається `php artisan test`.
* `php artisan test`, `vendor/bin/pint --test`, `php artisan route:list`,
  `php artisan migrate:fresh --seed` проходять чисто.
