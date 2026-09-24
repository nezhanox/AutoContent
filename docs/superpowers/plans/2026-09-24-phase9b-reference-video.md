# Phase 9b — референс-відео → проєкт Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Дати Claude Code (через нову project-scoped skill) можливість
перетворити локальне референс-відео на повністю налаштований
`ContentProject` і перевірене тестове відео у тому ж стилі/структурі —
сам дивлячись на кадри й читаючи транскрипт референсу, спираючись на
один новий тонкий CLI-адаптер і три вже існуючі з Phase 9a.

**Architecture:** Нуль нового LLM-плумбінгу, нуль змін схеми/Jobs, нуль
змін у `WhisperCliTranscriptionProvider`/`TranscriptionProviderInterface`.
Новий доменний сервіс `ExtractReferenceMediaService` (ffprobe/ffmpeg
через `Process`-фасад, той самий паттерн, що `FfmpegVideoRenderer`)
дістає з локального відеофайлу метадані, рівномірно розподілені кадри
й транскрипт (перевикористовуючи вже існуючий
`TranscriptionProviderInterface`). Тонка команда `video-reference:analyze`
не пише в БД і друкує єдиний JSON-рядок у stdout — той самий контракт,
що вже має `content-idea:draft` з Phase 9a. Уся "розумна" частина
(перегляд кадрів, оцінка стилю/пейсингу, рішення про ітерації) живе в
новій `.claude/skills/reference-video-to-project/SKILL.md`, яка після
аналізу переходить у той самий цикл `content-project:create` →
`content-idea:draft` → `content-idea:generate`, що вже є в
`idea-to-project` (Phase 9a).

**Tech Stack:** Laravel 12 / PHP 8.4, `Illuminate\Support\Facades\Process`
(ffmpeg/ffprobe CLI), Artisan console commands, PHPUnit Feature/Unit-тести
з `Process::fake()`, існуючий `TranscriptionProviderInterface`/
`FakeTranscriptionProvider`.

**Spec:** `docs/superpowers/specs/2026-09-24-phase9b-reference-video-design.md`

## Global Constraints

- Жодних нових міграцій/змін схеми БД.
- Жодних змін поведінки існуючих Jobs чи `WhisperCliTranscriptionProvider`/
  `TranscriptionProviderInterface`.
- Тести не роблять реальних CLI-викликів назовні — лише `Process::fake()`
  (той самий паттерн, що `FfmpegVideoRendererTest`) і
  `FakeTranscriptionProvider` (`docs/testing.md`).
- Стиль коду — `vendor/bin/pint --test`, дефолти Laravel, без
  кастомного `pint.json`.
- Нові artisan-команди в `app/Console/Commands/` — Laravel 12
  авто-дискавері їх без реєстрації в `bootstrap/app.php`.
- Шляхи кадрів у виведеному JSON — **відносні до `base_path()`**, ніколи
  абсолютні (host/container bind-mount сумісність — деталі в спеці,
  розділ "Новий artisan-command").
- Тимчасові артефакти аналізу пишуться в
  `storage/app/private/reference-analysis/{uuid}/` (вже гітігноровано
  через `storage/app/private/.gitignore`), без автоочищення — свідомо
  поза скоупом v1.
- Без scene-cut detection і без URL/`yt-dlp`-завантаження — v1 приймає
  лише вже існуючий локальний файл, рівномірний семплінг кадрів.

---

### Task 1: `ExtractReferenceMediaService`

**Files:**
- Create: `app/Domain/Video/Services/ExtractReferenceMediaService.php`
- Test: `tests/Unit/Domain/Video/ExtractReferenceMediaServiceTest.php`

**Interfaces:**
- Consumes: `App\Domain\Video\TranscriptionProviderInterface::transcribe(string $audioPath, ?string $language = null): TranscriptionResult` (вже існує, вже забіндений на `WhisperCliTranscriptionProvider` у `TranscriptionServiceProvider`, без жодних змін тут); `App\Domain\Video\Providers\FakeTranscriptionProvider` (вже існує, для тестів).
- Produces: `App\Domain\Video\Services\ExtractReferenceMediaService::analyze(string $sourcePath, int $frameCount, ?string $language, string $workDir): array{duration: float, width: int, height: int, frames: array<int, string>, transcript: array{language: string, segments: array<int, array{start: float, end: float, text: string}>}|null, notes: array<int, string>}` — кидає `RuntimeException` при відсутньому video-стрімі чи падінні ffmpeg/ffprobe. `$workDir` має вже існувати (створення каталогу — відповідальність викликача, Task 2).

- [ ] **Step 1: Написати падаючі тести сервісу**

Створити `tests/Unit/Domain/Video/ExtractReferenceMediaServiceTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Providers\FakeTranscriptionProvider;
use App\Domain\Video\Services\ExtractReferenceMediaService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class ExtractReferenceMediaServiceTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().'/reference_media_test_'.uniqid();
        File::makeDirectory($this->workDir, recursive: true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workDir);
        parent::tearDown();
    }

    private function fakeProcesses(bool $withAudio = true, float $duration = 10.0): void
    {
        Process::fake(function ($process) use ($withAudio, $duration) {
            $command = $process->command;

            if (in_array('-show_streams', $command, true)) {
                $streams = [['codec_type' => 'video', 'width' => 1080, 'height' => 1920]];

                if ($withAudio) {
                    $streams[] = ['codec_type' => 'audio'];
                }

                return Process::result(output: json_encode([
                    'format' => ['duration' => (string) $duration],
                    'streams' => $streams,
                ]));
            }

            $output = end($command);

            if (is_string($output) && (str_ends_with($output, '.wav') || str_ends_with($output, '.jpg'))) {
                file_put_contents($output, 'fake-bytes');
            }

            return Process::result(output: '');
        });
    }

    public function test_it_extracts_evenly_spaced_frames_and_transcribes_audio(): void
    {
        $this->fakeProcesses(withAudio: true, duration: 10.0);

        $provider = (new FakeTranscriptionProvider)->respondWith(
            [['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']],
            'en'
        );

        $service = new ExtractReferenceMediaService($provider);
        $result = $service->analyze('/fake/source.mp4', frameCount: 4, language: 'en', workDir: $this->workDir);

        $this->assertSame(10.0, $result['duration']);
        $this->assertSame(1080, $result['width']);
        $this->assertSame(1920, $result['height']);
        $this->assertSame(
            [
                $this->workDir.'/frame-01.jpg',
                $this->workDir.'/frame-02.jpg',
                $this->workDir.'/frame-03.jpg',
                $this->workDir.'/frame-04.jpg',
            ],
            $result['frames']
        );
        $this->assertSame(
            ['language' => 'en', 'segments' => [['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']]],
            $result['transcript']
        );
        $this->assertSame([], $result['notes']);
        $this->assertSame('en', $provider->lastLanguageArgument);
    }

    public function test_it_samples_frame_timestamps_at_the_midpoint_of_each_slice(): void
    {
        $timestamps = [];

        Process::fake(function ($process) use (&$timestamps) {
            $command = $process->command;

            if (in_array('-show_streams', $command, true)) {
                return Process::result(output: json_encode([
                    'format' => ['duration' => '8.0'],
                    'streams' => [['codec_type' => 'video', 'width' => 1080, 'height' => 1920]],
                ]));
            }

            $ssIndex = array_search('-ss', $command, true);

            if ($ssIndex !== false) {
                $timestamps[] = (float) $command[$ssIndex + 1];
            }

            $output = end($command);

            if (is_string($output) && str_ends_with($output, '.jpg')) {
                file_put_contents($output, 'fake-bytes');
            }

            return Process::result(output: '');
        });

        $service = new ExtractReferenceMediaService(new FakeTranscriptionProvider);
        $service->analyze('/fake/source.mp4', frameCount: 4, language: null, workDir: $this->workDir);

        $this->assertSame([1.0, 3.0, 5.0, 7.0], $timestamps);
    }

    public function test_it_skips_transcription_and_adds_a_note_when_there_is_no_audio_stream(): void
    {
        $this->fakeProcesses(withAudio: false, duration: 5.0);

        $provider = new FakeTranscriptionProvider;
        $service = new ExtractReferenceMediaService($provider);
        $result = $service->analyze('/fake/source.mp4', frameCount: 2, language: null, workDir: $this->workDir);

        $this->assertNull($result['transcript']);
        $this->assertSame(['No audio stream detected — transcript skipped.'], $result['notes']);
        $this->assertNull($provider->lastAudioPath);
    }

    public function test_it_throws_when_there_is_no_video_stream(): void
    {
        Process::fake(function ($process) {
            $command = $process->command;

            if (in_array('-show_streams', $command, true)) {
                return Process::result(output: json_encode([
                    'format' => ['duration' => '5.0'],
                    'streams' => [['codec_type' => 'audio']],
                ]));
            }

            return Process::result(output: '');
        });

        $service = new ExtractReferenceMediaService(new FakeTranscriptionProvider);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No video stream found');

        $service->analyze('/fake/source.mp4', frameCount: 2, language: null, workDir: $this->workDir);
    }

    public function test_it_throws_a_readable_error_when_ffprobe_fails(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'invalid data found', exitCode: 1)]);

        $service = new ExtractReferenceMediaService(new FakeTranscriptionProvider);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ffprobe failed: invalid data found');

        $service->analyze('/fake/not-a-video.txt', frameCount: 2, language: null, workDir: $this->workDir);
    }
}
```

- [ ] **Step 2: Прогнати тести і переконатись, що вони падають**

Run: `php artisan test --filter=ExtractReferenceMediaServiceTest`
Expected: FAIL — `Class "App\Domain\Video\Services\ExtractReferenceMediaService" not found`.

- [ ] **Step 3: Реалізувати сервіс**

Створити `app/Domain/Video/Services/ExtractReferenceMediaService.php`:

```php
<?php

namespace App\Domain\Video\Services;

use App\Domain\Video\TranscriptionProviderInterface;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class ExtractReferenceMediaService
{
    public function __construct(private readonly TranscriptionProviderInterface $transcriptionProvider) {}

    /**
     * @return array{
     *     duration: float,
     *     width: int,
     *     height: int,
     *     frames: array<int, string>,
     *     transcript: array{language: string, segments: array<int, array{start: float, end: float, text: string}>}|null,
     *     notes: array<int, string>,
     * }
     */
    public function analyze(string $sourcePath, int $frameCount, ?string $language, string $workDir): array
    {
        $probe = $this->probe($sourcePath);

        if (! $probe['has_video']) {
            throw new RuntimeException("No video stream found in [{$sourcePath}].");
        }

        $notes = [];
        $transcript = null;

        if ($probe['has_audio']) {
            $audioPath = "{$workDir}/audio.wav";
            $this->extractAudio($sourcePath, $audioPath);
            $result = $this->transcriptionProvider->transcribe($audioPath, $language);

            $transcript = [
                'language' => $result->language,
                'segments' => $result->segments,
            ];
        } else {
            $notes[] = 'No audio stream detected — transcript skipped.';
        }

        return [
            'duration' => $probe['duration'],
            'width' => $probe['width'],
            'height' => $probe['height'],
            'frames' => $this->extractFrames($sourcePath, $probe['duration'], $frameCount, $workDir),
            'transcript' => $transcript,
            'notes' => $notes,
        ];
    }

    /**
     * @return array{duration: float, width: int, height: int, has_video: bool, has_audio: bool}
     */
    private function probe(string $path): array
    {
        $result = Process::timeout(config('render.timeout'))->run([
            config('render.ffprobe_binary'), '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $path,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('ffprobe failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        $decoded = json_decode($result->output(), true);
        $streams = is_array($decoded['streams'] ?? null) ? $decoded['streams'] : [];
        $videoStream = collect($streams)->firstWhere('codec_type', 'video');
        $audioStream = collect($streams)->firstWhere('codec_type', 'audio');

        return [
            'duration' => (float) ($decoded['format']['duration'] ?? 0.0),
            'width' => (int) ($videoStream['width'] ?? 0),
            'height' => (int) ($videoStream['height'] ?? 0),
            'has_video' => $videoStream !== null,
            'has_audio' => $audioStream !== null,
        ];
    }

    private function extractAudio(string $sourcePath, string $audioPath): void
    {
        $result = Process::timeout(config('render.timeout'))->run([
            config('render.ffmpeg_binary'), '-y', '-i', $sourcePath,
            '-vn', '-acodec', 'pcm_s16le', '-ar', '16000', '-ac', '1',
            $audioPath,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('ffmpeg audio extraction failed: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    /**
     * @return array<int, string>
     */
    private function extractFrames(string $sourcePath, float $duration, int $frameCount, string $workDir): array
    {
        $frames = [];

        for ($i = 0; $i < $frameCount; $i++) {
            $timestamp = $duration * ($i + 0.5) / $frameCount;
            $framePath = sprintf('%s/frame-%02d.jpg', $workDir, $i + 1);

            $result = Process::timeout(config('render.timeout'))->run([
                config('render.ffmpeg_binary'), '-y', '-ss', (string) $timestamp, '-i', $sourcePath,
                '-frames:v', '1', '-q:v', '2', $framePath,
            ]);

            if ($result->failed()) {
                throw new RuntimeException("ffmpeg frame extraction failed at {$timestamp}s: ".trim($result->errorOutput() ?: $result->output()));
            }

            $frames[] = $framePath;
        }

        return $frames;
    }
}
```

- [ ] **Step 4: Прогнати тести і переконатись, що вони проходять**

Run: `php artisan test --filter=ExtractReferenceMediaServiceTest`
Expected: PASS (5/5).

- [ ] **Step 5: Pint + commit**

Run: `vendor/bin/pint app/Domain/Video/Services/ExtractReferenceMediaService.php tests/Unit/Domain/Video/ExtractReferenceMediaServiceTest.php`

```bash
git add app/Domain/Video/Services/ExtractReferenceMediaService.php tests/Unit/Domain/Video/ExtractReferenceMediaServiceTest.php
git commit -m "feat(video): add ExtractReferenceMediaService for reference-video analysis"
```

---

### Task 2: `video-reference:analyze` команда

**Files:**
- Create: `app/Console/Commands/AnalyzeVideoReferenceCommand.php`
- Test: `tests/Feature/Console/AnalyzeVideoReferenceCommandTest.php`

**Interfaces:**
- Consumes: `App\Domain\Video\Services\ExtractReferenceMediaService::analyze(string $sourcePath, int $frameCount, ?string $language, string $workDir): array{...}` (Task 1).
- Produces: artisan-команда `video-reference:analyze {path} {--frames=8} {--language=}` — друкує JSON у stdout (форма ідентична поверненню сервісу, але `frames` замінено на шляхи, відносні до `base_path()`), `self::SUCCESS`/`self::FAILURE`. Жодних записів у БД. Клас `App\Console\Commands\AnalyzeVideoReferenceCommand`.

- [ ] **Step 1: Написати падаючі тести команди**

Створити `tests/Feature/Console/AnalyzeVideoReferenceCommandTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Domain\Video\Providers\FakeTranscriptionProvider;
use App\Domain\Video\TranscriptionProviderInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AnalyzeVideoReferenceCommandTest extends TestCase
{
    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourcePath = sys_get_temp_dir().'/reference_command_test_'.uniqid().'.mp4';
        File::put($this->sourcePath, 'fake-video-bytes');
    }

    protected function tearDown(): void
    {
        if (is_file($this->sourcePath)) {
            unlink($this->sourcePath);
        }

        File::deleteDirectory(storage_path('app/private/reference-analysis'));

        parent::tearDown();
    }

    private function fakeFfmpegProcesses(bool $withAudio = true, float $duration = 10.0): void
    {
        Process::fake(function ($process) use ($withAudio, $duration) {
            $command = $process->command;

            if (in_array('-show_streams', $command, true)) {
                $streams = [['codec_type' => 'video', 'width' => 1080, 'height' => 1920]];

                if ($withAudio) {
                    $streams[] = ['codec_type' => 'audio'];
                }

                return Process::result(output: json_encode([
                    'format' => ['duration' => (string) $duration],
                    'streams' => $streams,
                ]));
            }

            $output = end($command);

            if (is_string($output) && (str_ends_with($output, '.wav') || str_ends_with($output, '.jpg'))) {
                file_put_contents($output, 'fake-bytes');
            }

            return Process::result(output: '');
        });
    }

    public function test_it_outputs_relative_frame_paths_and_transcript_json(): void
    {
        $this->fakeFfmpegProcesses(withAudio: true, duration: 10.0);
        $this->app->bind(TranscriptionProviderInterface::class, function () {
            return (new FakeTranscriptionProvider)->respondWith(
                [['start' => 0.0, 'end' => 1.0, 'text' => 'Hi there']],
                'en'
            );
        });

        $exitCode = Artisan::call('video-reference:analyze', [
            'path' => $this->sourcePath,
            '--frames' => 5,
        ]);

        $this->assertSame(0, $exitCode);

        $output = json_decode(Artisan::output(), true);

        $this->assertSame(10.0, $output['duration']);
        $this->assertSame(1080, $output['width']);
        $this->assertSame(1920, $output['height']);
        $this->assertCount(5, $output['frames']);

        foreach ($output['frames'] as $framePath) {
            $this->assertStringStartsWith('storage/app/private/reference-analysis/', $framePath);
        }

        $this->assertSame('en', $output['transcript']['language']);
        $this->assertSame('Hi there', $output['transcript']['segments'][0]['text']);
        $this->assertSame([], $output['notes']);
    }

    public function test_it_reports_a_note_and_null_transcript_when_there_is_no_audio_stream(): void
    {
        $this->fakeFfmpegProcesses(withAudio: false, duration: 6.0);

        $exitCode = Artisan::call('video-reference:analyze', [
            'path' => $this->sourcePath,
        ]);

        $this->assertSame(0, $exitCode);

        $output = json_decode(Artisan::output(), true);

        $this->assertNull($output['transcript']);
        $this->assertSame(['No audio stream detected — transcript skipped.'], $output['notes']);
    }

    public function test_it_fails_for_a_missing_file(): void
    {
        $exitCode = Artisan::call('video-reference:analyze', [
            'path' => '/no/such/file.mp4',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('File not found', Artisan::output());
    }

    public function test_it_rejects_an_out_of_range_frame_count(): void
    {
        $exitCode = Artisan::call('video-reference:analyze', [
            'path' => $this->sourcePath,
            '--frames' => 0,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--frames must be between 1 and 30', Artisan::output());
    }

    public function test_it_fails_with_a_readable_message_when_ffprobe_rejects_the_file(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'invalid data found when processing input', exitCode: 1)]);

        $exitCode = Artisan::call('video-reference:analyze', [
            'path' => $this->sourcePath,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Reference video analysis failed', Artisan::output());
    }
}
```

- [ ] **Step 2: Прогнати тести і переконатись, що вони падають**

Run: `php artisan test --filter=AnalyzeVideoReferenceCommandTest`
Expected: FAIL — command `video-reference:analyze` not defined.

- [ ] **Step 3: Реалізувати команду**

Створити `app/Console/Commands/AnalyzeVideoReferenceCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Domain\Video\Services\ExtractReferenceMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class AnalyzeVideoReferenceCommand extends Command
{
    protected $signature = 'video-reference:analyze
        {path : Path to a local reference video file}
        {--frames=8 : Number of evenly-spaced frames to extract}
        {--language= : ISO language hint for transcription, e.g. en/uk}';

    protected $description = 'Extract frames, audio transcript, and metadata from a local reference video (no DB writes).';

    public function handle(ExtractReferenceMediaService $service): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: [{$path}].");

            return self::FAILURE;
        }

        $frameCount = (int) $this->option('frames');

        if ($frameCount < 1 || $frameCount > 30) {
            $this->error('--frames must be between 1 and 30.');

            return self::FAILURE;
        }

        $workDir = storage_path('app/private/reference-analysis/'.(string) Str::uuid());
        File::makeDirectory($workDir, recursive: true);

        try {
            $result = $service->analyze(
                sourcePath: $path,
                frameCount: $frameCount,
                language: $this->option('language'),
                workDir: $workDir,
            );
        } catch (RuntimeException $exception) {
            $this->error("Reference video analysis failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $result['frames'] = array_map(
            fn (string $framePath): string => Str::after($framePath, base_path().DIRECTORY_SEPARATOR),
            $result['frames'],
        );

        $output = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($output === false) {
            $this->error('Failed to encode analysis output as JSON.');

            return self::FAILURE;
        }

        $this->line($output);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Прогнати тести і переконатись, що вони проходять**

Run: `php artisan test --filter=AnalyzeVideoReferenceCommandTest`
Expected: PASS (5/5).

- [ ] **Step 5: Pint + commit**

Run: `vendor/bin/pint app/Console/Commands/AnalyzeVideoReferenceCommand.php tests/Feature/Console/AnalyzeVideoReferenceCommandTest.php`

```bash
git add app/Console/Commands/AnalyzeVideoReferenceCommand.php tests/Feature/Console/AnalyzeVideoReferenceCommandTest.php
git commit -m "feat(console): add video-reference:analyze command"
```

---

### Task 3: `reference-video-to-project` skill

**Files:**
- Create: `.claude/skills/reference-video-to-project/SKILL.md`

**Interfaces:**
- Consumes: `video-reference:analyze` (Task 2, CLI), `content-project:create`/`content-idea:draft`/`content-idea:generate` (Phase 9a, вже існують, без змін) — усі як зовнішні CLI-команди, викликані з тексту skill-інструкції.
- Produces: нічого програмного — цей файл читає й виконує сам Claude Code в майбутніх сесіях.

- [ ] **Step 1: Написати SKILL.md**

Створити `.claude/skills/reference-video-to-project/SKILL.md`:

```markdown
---
name: reference-video-to-project
description: Use when the user gives a path to a local reference video (e.g. a saved TikTok clip) and wants AutoContent to configure a ContentProject that generates videos matching its style/structure — this skill extracts frames+transcript via a CLI adapter, inspects them itself, drafts and self-reviews a test script/scenes before committing to a full render.
---

# Референс-відео → проєкт (AutoContent)

Автоматизація Phase 9b: локальний референс-відеофайл користувача →
повністю налаштований `ContentProject`, що відтворює його стиль і
структуру → перевірене тестове відео. Аналіз кадрів/транскрипту і
самоперевірка якості — це твоя (Claude) власна робота, не LLM-виклик
усередині застосунку. Деталі архітектури:
`docs/superpowers/specs/2026-09-24-phase9b-reference-video-design.md`.

## Передумови

- `docker compose up -d --build` піднято, черга реально споживається
  (`worker`/`horizon` контейнери) — інакше `content-idea:generate`
  ніколи не завершить рендер.
- Працюєш із реальними, не Fake-провайдерами (`.env` застосунку вже
  налаштований користувачем раніше) — це реальна генерація, не тест.
- v1 приймає **тільки локальний файл** (шлях на диску, вже завантажений
  користувачем) — без завантаження за URL з TikTok/YouTube.

## Кроки

1. **Отримай шлях до референс-відео** від користувача (напр.
   `examples/videos/IMG_2514.MP4`).

2. **Проаналізуй його:**

   ```bash
   php artisan video-reference:analyze {path} --frames=8
   ```

   Команда нічого не пише в БД, лише друкує JSON з `duration`,
   `width`/`height`, `frames` (шляхи відносні до кореня проєкту),
   `transcript` (`null`, якщо в референсі немає аудіо-треку — тоді
   `notes` пояснює чому) і `notes`.

3. **Подивись на референс сам** (Claude, не LLM-виклик застосунку):
   - Відкрий кожен шлях з `frames` через `Read`-тул (це зображення,
     шлях у JSON — відносний до кореня проєкту, тож читай його як
     `{корінь_проєкту}/{шлях_з_frames}`) — оціни візуальний стиль
     (кольори, наявність тексту на екрані, композиція, чи це
     talking-head/b-roll/стокові кадри/анімація).
   - Якщо `transcript` не `null` — прочитай `transcript.segments`,
     оціни тон озвучки, темп мовлення, структуру (хук у перші секунди?
     CTA в кінці?), мову оригіналу.
   - Прикинь приблизну "щільність монтажу" зіставивши кількість кадрів
     і `duration` — v1 не робить точного scene-detection, це лише
     орієнтовна оцінка "швидкий монтаж" vs "довгі кадри", досить для
     нотатки в `style`.

4. **Визнач налаштування проєкту** (та сама структура, що в
   `idea-to-project`, плюс структурні нотатки в `style`):
   - `niche` — коротка категорія
   - `language` — ISO-код (з транскрипту, якщо є; інакше — з
     візуального контексту, напр. мова тексту на екрані)
   - `tone` — вільний текст
   - `style` — вільний текст, **явно включає** спостережений
     пейсинг/структуру (напр. "energetic, hook in first 2s, cuts every
     2-3s, bold on-screen captions, upbeat VO") — цей текст напряму
     йде в system-промпт генерації сценарію (`Style: %s`), тож жодних
     нових полів схеми/БД не існує і не потрібно
   - `target_platforms` — підмножина `tiktok`/`youtube`/`instagram`/`x`
   - Початковий `title`/`topic` для чернетки — **не копія** референсу
     (авторське право/оригінальність), а власна тема в тому самому
     стилі

5. **Створи проєкт:**

   ```bash
   php artisan content-project:create "Назва проєкту" \
     --niche=... --language=... \
     --platform=tiktok --platform=youtube \
     --tone="..." --style="..."
   ```

   Запам'ятай надрукований `id` проєкту.

6. **Чернетка (до 2 ітерацій).** Для кожної ітерації придумай
   `title`+`topic` сам (в тому самому стилі, що й референс) і виклич:

   ```bash
   php artisan content-idea:draft {project_id} "Заголовок" "topic текст"
   ```

   Команда нічого не пише в БД — можна викликати скільки завгодно
   разів. Прочитай JSON (`script.script`, `scenes[].text`) і сам
   оціни: чи витримано пейсинг/структуру/тон референсу, чи логічна
   структура сцен, чи немає фактичних помилок/нісенітниці. Якщо ні —
   зміни `title`/`topic` (і за потреби tone/style проєкту через
   `php artisan tinker --execute="App\Models\ContentProject::where('id', {id})->update(['settings->style' => '...'])"`
   — саме через query builder (`::where(...)->update(...)`), НЕ через
   `::find({id})->update(...)`: Eloquent-форма мовчки відкидає ключ
   `'settings->style'`, бо він не входить у `$fillable`, і апдейт не
   застосовується) і повтори. Максимум 2 повторні спроби чернетки
   (тобто до 3 викликів `content-idea:draft` всього) — після цього
   переходь до фіналу з тим, що є, чесно попередивши користувача про
   залишкові сумніви.

7. **Фінал — повний рендер:**

   ```bash
   php artisan content-idea:generate {project_id} "фінальний topic текст"
   ```

   Це реальна LLM-генерація ідеї (може дати трохи інший title/topic,
   ніж чернетка — нормально) + весь автоматичний ланцюжок job'ів до
   рендеру. Зачекай завершення, періодично перевіряючи статус:

   ```bash
   php artisan tinker --execute="dump(App\Models\Video::where('content_project_id', {project_id})->latest('id')->first(['id','status','failed_stage','error_message']))"
   ```

   Рядок `Video` створюється не одразу, а лише коли `GenerateScenesJob`
   доходить до відповідного кроку — тож одразу після запуску
   `content-idea:generate` цей запит може легітимно повернути `null`
   протягом короткого часу. Це не ознака помилки — просто зачекай і
   повтори запит.

   Статуси проходять `Draft → ScriptGenerated → VoiceGenerated →
   AssetsReady → Rendering → Rendered → Approved` (див.
   `docs/architecture.md` §2) — окремого статусу для перевірки якості
   немає: коли `status=Rendered`, `QualityCheckVideoJob` не міняє
   статус, а виставляє на тому ж рядку `Video` поля `quality_passed`
   (bool) і `quality_report` (масив `checks`/`notes`/`metadata`).
   `Approved` — ручна дія адміна, автоматичний пайплайн сам туди не
   доходить: фінальний очікуваний статус — `Rendered`. Якщо
   `status=Failed` — прочитай `failed_stage`/`error_message`, це вже
   технічна проблема поза скоупом цього skill (не намагайся мовчки
   перезапускати рендер втретє).

8. **Звітуй користувачу:**
   - Посилання на відео (`/console/videos` або `/admin/videos/{id}`)
     і на проєкт (`/admin/content-projects/{id}`).
   - Коротко — що саме зі стилю/структури референсу перенесено (пейсинг,
     тон, наявність тексту на екрані тощо), які `niche`/`tone`/`style`/
     `target_platforms` обрано, скільки чернеткових ітерацій знадобилось
     і що саме коригувалось.
   - Якщо в референсі не було аудіо — чесно скажи, що оцінка тону
     ґрунтувалась лише на візуальному ряді.
   - Якщо фінал не пройшов технічну перевірку — чесно скажи це, не
     видавай за успіх.
```

- [ ] **Step 2: Commit**

```bash
git add .claude/skills/reference-video-to-project/SKILL.md
git commit -m "docs(skill): add reference-video-to-project skill"
```

---

## Post-implementation verification (controller, not a subagent task)

Після завершення всіх трьох задач і фінального whole-branch review:

1. `php artisan test`, `vendor/bin/pint --test`, `php artisan route:list`,
   `php artisan migrate:fresh --seed` — без регресій.
2. Реальний прогін (не Fake) `php artisan video-reference:analyze
   examples/videos/IMG_2514.MP4 --frames=8` — підтвердити валідний JSON,
   фізичну наявність 8 кадрів за вказаними відносними шляхами і реальний
   Whisper-транскрипт.
3. Наскрізна ручна перевірка через нову skill на `examples/videos/IMG_2514.MP4`
   до фінального рендеру — підтвердити `Video.status=Rendered` і
   `quality_passed`.
