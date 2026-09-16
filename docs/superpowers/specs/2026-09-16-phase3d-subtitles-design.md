# Phase 3d — Subtitles: design spec

Джерело: `TechnicalTask.md` (розділи 4, 7, 8, 9, 11, 17, 19, 22), `ROADMAP.md` (Phase 3,
частина "Subtitles: Whisper як Python CLI worker, Laravel отримує structured JSON,
генерація ASS/SRT").

## Мета

Від `Video` з готовою бібліотекою asset'ів (Phase 3c) до `.srt`-файлу з timestamps,
згенерованого через Whisper. Четверта з п'яти запланованих під-фаз Phase 3 (3a Сцени
→ 3b Voiceover → 3c Assets → **3d Subtitles** → 3e Rendering+QualityCheck). Перша
фаза проєкту, що реально запускає зовнішній процес (не HTTP-виклик) — Python
CLI-скрипт через Laravel `Process` (обгортка над Symfony Process, розділ 9 ТЗ).

## Скоуп

Входить:

* `app/Domain/Video/TranscriptionProviderInterface.php` + `TranscriptionResult` DTO
  (розділ 6.2 ТЗ — той самий generic-провайдер-патерн, що `TtsProviderInterface`/
  `AssetProviderInterface`).
* `app/Domain/Video/Providers/WhisperCliTranscriptionProvider.php` — реальна
  реалізація: `Illuminate\Support\Facades\Process` запускає `scripts/
  whisper_transcribe.py`, парсить structured JSON зі stdout (формат — точно приклад
  з розділу 11 ТЗ: `{"segments": [{"start", "end", "text"}], "language"}`).
  `app/Domain/Video/Providers/FakeTranscriptionProvider.php` — тестовий двійник.
* `scripts/whisper_transcribe.py` — Python CLI-скрипт на **faster-whisper**
  (CTranslate2), не `openai-whisper`/PyTorch — суттєво легше для CPU-only Docker
  image (десятки МБ проти кількох ГБ).
* `app/Providers/TranscriptionServiceProvider.php` — біндинг напряму на
  `WhisperCliTranscriptionProvider` (без Manager-шару, той самий підхід, що
  `TtsServiceProvider`/`AssetServiceProvider`).
* `config/whisper.php`.
* `app/Domain/Video/Support/SrtFormatter.php` — чиста функція
  `segments[] → .srt`-текст, незалежно тестована.
* `app/Domain/Video/Services/GenerateSubtitlesService.php`.
* ALTER-міграція: `videos.subtitle_id` — nullable FK на `media_assets.id`,
  `nullOnDelete()`.
* `app/Jobs/GenerateSubtitlesJob.php` — idempotent, timeout/retry.
* Docker: `docker/worker/Dockerfile` отримує `python3`/`pip`/`faster-whisper`;
  `docker-compose.yml` отримує named volume для кешу моделі Whisper.
* Filament: row action "Generate Subtitles" на `VideosTable`.
* Feature/Unit-тести: провайдер (через `Process::fake()`, без реального Python),
  `SrtFormatter`, сервіс, job, Filament-дія.

Не входить (свідомо відкладено):

* ASS-генерація зі стилізацією (шрифт/позиція/розмір/margins — розділ 10 ТЗ) —
  прив'язана до render-template, тому належить Phase 3e: рендерер сам конвертує
  `segments` (з `MediaAsset.metadata`) у стилізований ASS під час burn-in.
* `VideoRendererInterface`/`FfmpegVideoRenderer`/`RenderVideoJob`/`QualityCheckJob`
  — Phase 3e.
* Нова модель `Subtitle` — розділ 4 ТЗ явно перелічує 11 моделей без окремого
  "Subtitle"; `MediaAssetType` вже має значення `subtitle` (Phase 1). Результат
  зберігається як звичайний `MediaAsset`, без нової таблиці.
* Нове значення `VideoStatus` (напр. "subtitles_ready") — той самий підхід, що
  сцени в Phase 3a: наявність субтитрів виражається структурно (`Video.subtitle_id
  !== null`), а не окремим статусом; `Video.status` лишається `AssetsReady` після
  3d. Наступний реальний перехід (`Rendering`) — відповідальність 3e.
* Запікання Whisper-моделі в Docker-образ на build-етапі — модель кешується у
  volume при першому реальному запуску, щоб не ускладнювати build мережевою
  залежністю.

## Рішення (там, де ТЗ не фіксує деталь явно)

### `TranscriptionProviderInterface` і DTO

```php
interface TranscriptionProviderInterface
{
    public function transcribe(string $audioPath, ?string $language = null): TranscriptionResult;
}
```

```php
final class TranscriptionResult
{
    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $segments
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly array $segments,
        public readonly string $language,
        public readonly array $metadata = [],
    ) {}
}
```

`transcribe()` приймає **локальний** шлях до файлу — інтерфейс навмисно не знає про
`Storage`/диски (той самий принцип, що `TtsProviderInterface::generate()` приймає
голий текст, а не `Video`); матеріалізація аудіо з S3/local у тимчасовий локальний
файл — відповідальність `GenerateSubtitlesService`, не провайдера.

### `WhisperCliTranscriptionProvider` — `Illuminate\Support\Facades\Process`, не сирий Symfony Process

ТЗ (розділ 9) каже "для запуску процесів використовувати Symfony Process" — у
проєкті вже усталений підхід не викликати сторонні бібліотеки напряму, а через
Laravel-обгортку (`Http` замість Guzzle в `OpenAiLlmProvider`/
`AnthropicLlmProvider`/`ElevenLabsTtsProvider`). `Illuminate\Support\Facades\Process`
— саме така обгортка над Symfony Process, з тим самим testability-бенефітом:
`Process::fake()` замінює виклик повністю, тест не потребує реального Python/
Whisper на машині (той самий принцип, що `Http::fake()` для TTS/LLM).

```php
final class WhisperCliTranscriptionProvider implements TranscriptionProviderInterface
{
    public function transcribe(string $audioPath, ?string $language = null): TranscriptionResult
    {
        $command = [
            config('whisper.python_binary'),
            config('whisper.script_path'),
            '--audio', $audioPath,
            '--model', config('whisper.model'),
        ];

        if ($language !== null) {
            $command[] = '--language';
            $command[] = $language;
        }

        $result = Process::timeout(config('whisper.timeout'))->run($command);

        if ($result->failed()) {
            throw new RuntimeException(
                'Whisper transcription failed: '.trim($result->errorOutput() ?: $result->output())
            );
        }

        $decoded = json_decode($result->output(), true);

        if (! is_array($decoded) || ! isset($decoded['segments']) || ! is_array($decoded['segments'])) {
            throw new RuntimeException('Whisper transcription returned invalid JSON: '.$result->output());
        }

        return new TranscriptionResult(
            segments: array_map(static fn (array $segment): array => [
                'start' => (float) $segment['start'],
                'end' => (float) $segment['end'],
                'text' => trim((string) $segment['text']),
            ], $decoded['segments']),
            language: (string) ($decoded['language'] ?? $language ?? 'en'),
            metadata: ['model' => config('whisper.model')],
        );
    }
}
```

Увесь ланцюг помилок (ненульовий exit code, невалідний JSON) звужується до
`\RuntimeException` — той самий підхід, що всі попередні провайдери
(`ElevenLabsTtsProvider`, `OpenAiLlmProvider`).

`FakeTranscriptionProvider` (`respondWith`-стиль, той самий підхід, що
`FakeTtsProvider`/`FakeAssetProvider`):

```php
final class FakeTranscriptionProvider implements TranscriptionProviderInterface
{
    /** @var array<int, array{start: float, end: float, text: string}> */
    private array $segments = [];

    private string $language = 'en';

    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $segments
     */
    public function respondWith(array $segments, string $language = 'en'): static
    {
        $this->segments = $segments;
        $this->language = $language;

        return $this;
    }

    public function transcribe(string $audioPath, ?string $language = null): TranscriptionResult
    {
        return new TranscriptionResult(segments: $this->segments, language: $this->language);
    }
}
```

### `scripts/whisper_transcribe.py`

```python
import argparse
import json

from faster_whisper import WhisperModel


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--audio", required=True)
    parser.add_argument("--model", default="base")
    parser.add_argument("--language", default=None)
    args = parser.parse_args()

    model = WhisperModel(args.model, device="cpu", compute_type="int8")
    segments, info = model.transcribe(args.audio, language=args.language)

    result = {
        "language": info.language,
        "segments": [
            {"start": segment.start, "end": segment.end, "text": segment.text.strip()}
            for segment in segments
        ],
    }

    print(json.dumps(result))


if __name__ == "__main__":
    main()
```

`compute_type="int8"` — квантизація для прийнятної швидкості на CPU без GPU (типове
розгортання цього MVP); `device="cpu"` — явно, без спроби автовизначення GPU, яке
було б непередбачуваним у контейнері.

### `config/whisper.php`

```php
return [
    'python_binary' => env('WHISPER_PYTHON_BINARY', 'python3'),
    'script_path' => env('WHISPER_SCRIPT_PATH', base_path('scripts/whisper_transcribe.py')),
    'model' => env('WHISPER_MODEL', 'base'),
    'timeout' => (int) env('WHISPER_TIMEOUT', 600),
];
```

`.env.example` отримує `WHISPER_PYTHON_BINARY`, `WHISPER_MODEL`, `WHISPER_TIMEOUT`
(без `WHISPER_SCRIPT_PATH` — дефолт через `base_path()` достатній, без секретів,
розділ 18 ТЗ). `timeout=600` — суттєво довше за TTS/LLM HTTP-таймаути (120с): Whisper
на CPU без GPU може працювати повільніше за realtime для довшого аудіо, а не
секунди HTTP round-trip.

### `SrtFormatter` — окремий, незалежно тестований юніт

```php
final class SrtFormatter
{
    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $segments
     */
    public static function format(array $segments): string
    {
        $blocks = [];

        foreach ($segments as $index => $segment) {
            $blocks[] = ($index + 1)."\n"
                .self::timestamp($segment['start']).' --> '.self::timestamp($segment['end'])."\n"
                .$segment['text'];
        }

        return implode("\n\n", $blocks)."\n";
    }

    private static function timestamp(float $seconds): string
    {
        $whole = (int) floor($seconds);
        $hours = intdiv($whole, 3600);
        $minutes = intdiv($whole % 3600, 60);
        $secs = $whole % 60;
        $millis = (int) round(($seconds - $whole) * 1000);

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $millis);
    }
}
```

Формат — стандартний SRT (`HH:MM:SS,mmm --> HH:MM:SS,mmm`), без стилізації —
навмисно мінімальний, ASS зі стилями генерується в 3e.

### `GenerateSubtitlesService` — матеріалізація аудіо у тимчасовий файл, мова з `ContentProject.language`

```php
final class GenerateSubtitlesService
{
    public function __construct(private readonly TranscriptionProviderInterface $transcriptionProvider) {}

    /**
     * @return array{segments: array<int, array{start: float, end: float, text: string}>, language: string, srt: string}
     */
    public function generate(Video $video): array
    {
        $disk = Storage::disk(config('filesystems.default'));
        $tempPath = tempnam(sys_get_temp_dir(), 'voiceover_').'.mp3';

        try {
            file_put_contents($tempPath, $disk->get($video->voiceover->file_path));
            $result = $this->transcriptionProvider->transcribe($tempPath, $video->contentProject->language);
        } finally {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }

        return [
            'segments' => $result->segments,
            'language' => $result->language,
            'srt' => SrtFormatter::format($result->segments),
        ];
    }
}
```

Той самий "поза транзакцією, без DB-запису" підхід, що `GenerateVoiceoverService`.
`ContentProject.language` — обов'язкова (NOT NULL) колонка з Phase 1, завжди є
значення, фолбек не потрібен (на відміну від TTS voice, якого дійсно може не бути
ніде). Тимчасовий файл видаляється в `finally` незалежно від успіху/провалу виклику
— працює однаково для `local` і `s3` дисків, оскільки Whisper CLI вимагає локальний
шлях, а `Storage`-абстракція цього не гарантує напряму.

### `videos.subtitle_id` — без нової моделі, без unique-індексу

```php
Schema::table('videos', function (Blueprint $table) {
    $table->foreignId('subtitle_id')->nullable()->after('thumbnail_path')
        ->constrained('media_assets')->nullOnDelete();
});
```

`Video::subtitle(): BelongsTo` → `MediaAsset`. Ідемпотентність — структурна: одна
nullable FK-колонка фізично не може вказувати на два субтитри одночасно, тож окремий
unique-індекс (як `voiceovers.video_id` у 3b) не потрібен — на відміну від
`Voiceover`, тут немає окремої таблиці зі своїм `video_id`.

### `GenerateSubtitlesJob` — guard, транзакція

```php
class GenerateSubtitlesJob implements ShouldBeUnique, ShouldQueue
{
    public int $timeout = 650; // трохи більше за whisper.timeout (600с) — запас на I/O
    public int $tries = 3;
    public function uniqueId(): string { return (string) $this->videoId; }
    public int $uniqueFor = 700;
    public function backoff(): array { return [10, 30, 60]; }
}
```

`handle()`:

1. `Video::with(['voiceover', 'contentProject'])->findOrFail($this->videoId)`.
2. Guard: якщо `status !== VideoStatus::AssetsReady` АБО `subtitle_id !== null` —
   вихід без дій (порядок пайплайна з розділу 7 ТЗ: Voiceover → Assets → Subtitles;
   другий guard — ідемпотентність, той самий подвійний-guard підхід, що 3a/3b).
3. `GenerateSubtitlesService::generate($video)` — поза транзакцією (Whisper-виклик,
   може кинути `\RuntimeException`).
4. `Storage::disk(...)->put("projects/{$video->content_project_id}/subtitles/
   {$video->id}.srt", $result['srt']);` — запис файлу поза транзакцією, після
   успішного зовнішнього виклику (той самий урок, що `GenerateVoiceoverJob`).
5. `DB::transaction()`: `MediaAsset::create([type: Subtitle, provider: 'whisper',
   path, mime_type: 'application/x-subrip', metadata: [segments, language], hash:
   sha256(srt)])` + `$video->update(['subtitle_id' => $subtitle->id])` — атомарно
   разом.

`failed(Throwable $exception)`: `Log::channel('video')->error(...)` — без зміни
DB-стану (той самий підхід, що `GenerateScenesJob`/`GenerateVoiceoverJob`/
`CollectVideoAssetsJob`; відомий, вже занотований у ROADMAP гап — permanent failure
не дає користувачу видимого сигналу окрім логу).

### Docker

`docker/worker/Dockerfile` — додати після існуючих `apt-get install`:

```dockerfile
RUN apt-get update && apt-get install -y python3 python3-pip \
    && pip3 install --no-cache-dir --break-system-packages faster-whisper \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
```

`--break-system-packages` — потрібен на Debian bookworm (базовий образ `php:8.4-cli`)
через PEP 668; **ризик, що потребує підтвердження при реальному білді** — базовий
образ і точний прапорець верифікуються під час імплементації, не лише за
документацією.

`docker-compose.yml` — `worker`-сервіс отримує named volume для кешу
`faster-whisper`/huggingface (модель не запікається в образ, розділ "Не входить"):

```yaml
  worker:
    ...
    volumes:
      - .:/var/www/html
      - whisper_models:/root/.cache/huggingface
```

і в кореневому `volumes:`:

```yaml
volumes:
  postgres_data:
  whisper_models:
```

### Filament

Row action "Generate Subtitles" на `app/Filament/Resources/Videos/Tables/
VideosTable.php`:

```php
Action::make('generateSubtitles')
    ->label('Generate Subtitles')
    ->visible(fn (Video $record): bool => $record->status === VideoStatus::AssetsReady
        && $record->subtitle_id === null)
    ->requiresConfirmation()
    ->action(function (Video $record): void {
        GenerateSubtitlesJob::dispatch($record->id);
        Notification::make()->title('Subtitle generation queued')->success()->send();
    }),
```

### Тести

* Unit: `WhisperCliTranscriptionProviderTest` — через `Process::fake()`, без
  реального Python/Whisper: успішний JSON, ненульовий exit code, невалідний
  JSON-вивід, коректна побудова аргументів команди (`--audio`/`--model`/
  `--language`).
* Unit: `SrtFormatterTest` — коректний timestamp-формат (включно з переходом через
  годину/хвилину), порожній масив сегментів, кілька сегментів підряд.
* Feature: `GenerateSubtitlesServiceTest` — тимчасовий файл матеріалізується і
  видаляється (включно з випадком, коли провайдер кидає виняток — `finally`),
  мова резолвиться з `ContentProject.language`, `FakeTranscriptionProvider`.
* Feature: `GenerateSubtitlesJobTest` — happy path (`MediaAsset` створюється,
  `Video.subtitle_id` заповнюється, файл записаний на диск), no-op якщо
  `status !== AssetsReady`, no-op якщо `subtitle_id` вже заповнено, ідемпотентний
  повторний запуск.
* Feature: Filament-тест на видимість/dispatch дії `generateSubtitles`.

## Acceptance criteria (для 3d, частина ширшого DoD Phase 3 — пункт 7 розділу 24 ТЗ)

* Можна натиснути "Generate Subtitles" на `Video` з готовими asset'ами й отримати
  `MediaAsset` (type=subtitle, `.srt`-файл на диску, `metadata.segments` заповнено),
  `Video.subtitle_id` вказує на нього.
* Повторний dispatch того самого `Video` не створює другий `MediaAsset` і не змінює
  вже встановлений `subtitle_id`.
* Жодного реального виклику Whisper у тестах — уся сюїта проходить без Python/
  faster-whisper, встановлених на машині, де запускається `php artisan test`.
* `php artisan test`, `vendor/bin/pint --test`, `php artisan route:list`,
  `php artisan migrate:fresh --seed` проходять чисто.
