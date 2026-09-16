# Phase 3d — Subtitles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From a `Video` with a ready asset library (Phase 3c), a Whisper transcription of its `Voiceover` audio produces a `.srt` file with timestamps, stored as a `MediaAsset` (type `subtitle`) and linked back via `Video.subtitle_id` — closing the Subtitles slice of Phase 3's DoD (TechnicalTask.md §24 item 7), with zero dependency on a real Python/Whisper installation in the test suite.

**Architecture:** A new `TranscriptionProviderInterface` (mirrors the one-method shape of `TtsProviderInterface`/`AssetProviderInterface`) bound directly to `WhisperCliTranscriptionProvider`, which shells out to a Python CLI script via Laravel's `Illuminate\Support\Facades\Process` (a thin, fakeable wrapper around Symfony Process — the first process-invocation integration in this project, following the same "wrap the raw dependency behind a Laravel facade" convention as `Http` for every HTTP-based provider). `app/Domain/Video/Support/SrtFormatter` is a small, independently-tested pure function turning Whisper segments into `.srt` text. `app/Domain/Video/Services/GenerateSubtitlesService` materializes the voiceover audio to a temp local file (works identically for `local` and `s3` disks), calls the provider, and returns data for the job to persist — no DB writes, mirroring `GenerateVoiceoverService`/`CollectVideoAssetsService`. `app/Jobs/GenerateSubtitlesJob` mirrors the idempotency pattern of `CollectVideoAssetsJob` (status guard + a second "already done" guard, since — like Assets — there's no separate table needing a DB-level unique constraint here: `videos.subtitle_id` is a single nullable FK column, physically incapable of pointing at two subtitles at once). A Filament row action on `VideosTable` triggers it. Finally, `docker/worker/Dockerfile` and `docker-compose.yml` get the Python/faster-whisper toolchain the real provider needs at runtime — untested by PHPUnit, verified by a best-effort image build.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL, `Illuminate\Support\Facades\Process` (Symfony Process wrapper, `Process::fake()` for tests), Python 3 + `faster-whisper` (CTranslate2) for the real provider, `filament/filament` v4.13.

**Spec:** `docs/superpowers/specs/2026-09-16-phase3d-subtitles-design.md`

## Global Constraints

- `TranscriptionProviderInterface::transcribe(string $audioPath, ?string $language = null): TranscriptionResult` — one method, no manager/registry layer; `TranscriptionServiceProvider` binds it straight to `WhisperCliTranscriptionProvider` (spec, `TranscriptionProviderInterface`).
- `TranscriptionResult::__construct(array $segments, string $language, array $metadata = [])` — `$segments` is `array<int, array{start: float, end: float, text: string}>` (spec, same section).
- The interface takes a **local filesystem path**, never a `Storage` disk/key — materializing from S3/local is the service's job, not the provider's (spec, same section).
- `WhisperCliTranscriptionProvider` builds the command as an array (`[python_binary, script_path, '--audio', $audioPath, '--model', $model]`, plus `--language $language` only when `$language !== null`), runs it via `Process::timeout(config('whisper.timeout'))->run($command)`, and collapses every failure mode (non-zero exit, invalid JSON) to a single `\RuntimeException` — identical error-collapsing convention to every other provider in this codebase (spec, `WhisperCliTranscriptionProvider`).
- `config/whisper.php`: `python_binary` (env `WHISPER_PYTHON_BINARY`, default `python3`), `script_path` (env `WHISPER_SCRIPT_PATH`, default `base_path('scripts/whisper_transcribe.py')`), `model` (env `WHISPER_MODEL`, default `base`), `timeout` (env `WHISPER_TIMEOUT`, default `600`) (spec, `config/whisper.php`).
- `SrtFormatter::format(array $segments): string` — pure static method, no DB/IO, standard SRT timestamp format `HH:MM:SS,mmm` (spec, `SrtFormatter`).
- `GenerateSubtitlesService::generate(Video $video): array` returns `array{segments: array<int, array{start: float, end: float, text: string}>, language: string, srt: string}` — no DB writes; the job persists. Materializes `$video->voiceover->file_path` from `Storage::disk(config('filesystems.default'))` into a `tempnam()`-created local file, passes that path to the provider, and **always** deletes the temp file in a `finally` block, even when the provider throws. Language passed to the provider is `$video->contentProject->language` (a required, NOT NULL column since Phase 1 — no fallback needed, unlike TTS voice resolution) (spec, `GenerateSubtitlesService`).
- `videos.subtitle_id`: nullable `foreignId` to `media_assets.id`, `nullOnDelete()` — no new `Subtitle` model, no unique index needed (a single nullable FK column can't point at two rows at once) (spec, `videos.subtitle_id`).
- No new `VideoStatus` case. `Video.status` stays `AssetsReady` after this phase; subtitle existence is expressed structurally via `subtitle_id !== null` (spec, Скоуп — не входить).
- `GenerateSubtitlesJob`: `$timeout = 650`, `$tries = 3`, `backoff() = [10, 30, 60]`, `ShouldBeUnique` keyed by `video_id`, `uniqueFor = 700` (spec, `GenerateSubtitlesJob`).
- `handle()` order: guard (`status !== AssetsReady` OR `subtitle_id !== null` → no-op) → `GenerateSubtitlesService::generate()` outside any transaction (may throw) → **write the `.srt` file to disk** at `projects/{video->content_project_id}/subtitles/{video->id}.srt` → `DB::transaction()` creating the `MediaAsset` (`type: MediaAssetType::Subtitle`, `provider: 'whisper'`, `mime_type: 'application/x-subrip'`, `metadata: ['segments' => ..., 'language' => ...]`, `hash: hash('sha256', $srt)`) and updating `Video.subtitle_id` together (spec, same section — external side-effects before the DB transaction, same lesson carried from every prior phase's job).
- `failed()` only logs to the `video` channel — no DB state change (spec, same section, same convention as every prior job in this project).
- "Generate Subtitles" row action on `VideosTable` only, visible when `Video.status === AssetsReady` AND `subtitle_id === null` (spec, `Filament`).
- Docker: `python3`/`python3-pip`/`faster-whisper` added to `docker/worker/Dockerfile` only (not `docker/php/Dockerfile` — subtitle generation is a queue job, runs on `worker`, never in an HTTP request); model is **not** baked into the image — a named volume caches it on first real use (spec, `Docker`).
- No changes to `VideoResource`/`VideoSceneResource`/`MediaAssetResource` beyond what's listed above (spec, Скоуп — не входить, consistent with every prior sub-phase of Phase 3).

---

### Task 1: Transcription provider contracts — `TranscriptionProviderInterface`, `WhisperCliTranscriptionProvider`, `FakeTranscriptionProvider`

**Files:**
- Create: `app/Domain/Video/TranscriptionResult.php`
- Create: `app/Domain/Video/TranscriptionProviderInterface.php`
- Create: `app/Domain/Video/Providers/WhisperCliTranscriptionProvider.php`
- Create: `app/Domain/Video/Providers/FakeTranscriptionProvider.php`
- Create: `app/Providers/TranscriptionServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Create: `config/whisper.php`
- Modify: `.env.example`
- Create: `scripts/whisper_transcribe.py`
- Test: `tests/Unit/Domain/Video/WhisperCliTranscriptionProviderTest.php`

**Interfaces:**
- Consumes: nothing (bottom of the dependency chain, like every provider layer before it).
- Produces: `TranscriptionProviderInterface::transcribe(string $audioPath, ?string $language = null): TranscriptionResult`, `TranscriptionResult::__construct(array $segments, string $language, array $metadata = [])`, `FakeTranscriptionProvider::respondWith(array $segments, string $language = 'en'): static` plus public properties `$lastAudioPath`/`$lastLanguageArgument` for test inspection — consumed by Task 3's `GenerateSubtitlesService` and every later test that binds a fake transcription provider.

- [ ] **Step 1: Create the DTO and the interface**

`app/Domain/Video/TranscriptionResult.php`:

```php
<?php

namespace App\Domain\Video;

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

`app/Domain/Video/TranscriptionProviderInterface.php`:

```php
<?php

namespace App\Domain\Video;

interface TranscriptionProviderInterface
{
    public function transcribe(string $audioPath, ?string $language = null): TranscriptionResult;
}
```

There is no test to run for this step — plain value types with no behavior. Proceed to Step 2.

- [ ] **Step 2: Create `config/whisper.php`, add `.env.example` entries, and create the Python script**

`config/whisper.php`:

```php
<?php

return [
    'python_binary' => env('WHISPER_PYTHON_BINARY', 'python3'),
    'script_path' => env('WHISPER_SCRIPT_PATH', base_path('scripts/whisper_transcribe.py')),
    'model' => env('WHISPER_MODEL', 'base'),
    'timeout' => (int) env('WHISPER_TIMEOUT', 600),
];
```

In `.env.example`, insert this block immediately after the existing `ELEVENLABS_DEFAULT_VOICE=` line (line 71) and before the `# --- S3-compatible storage ---` comment:

```

WHISPER_PYTHON_BINARY=python3
WHISPER_MODEL=base
WHISPER_TIMEOUT=600
```

`scripts/whisper_transcribe.py` (new directory `scripts/` at repo root):

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

No test for this step (config file and a Python script that Task 6's Docker image is what actually runs it — PHPUnit never executes this script directly; Task 1's provider test fakes the process boundary instead).

- [ ] **Step 3: Write the failing tests for `WhisperCliTranscriptionProvider`**

`tests/Unit/Domain/Video/WhisperCliTranscriptionProviderTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Providers\WhisperCliTranscriptionProvider;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class WhisperCliTranscriptionProviderTest extends TestCase
{
    public function test_it_parses_a_successful_transcription(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode([
                'language' => 'en',
                'segments' => [
                    ['start' => 0.0, 'end' => 2.4, 'text' => '  This AI just changed coding  '],
                ],
            ])),
        ]);

        $provider = new WhisperCliTranscriptionProvider;
        $result = $provider->transcribe('/tmp/audio.mp3', 'en');

        $this->assertSame('en', $result->language);
        $this->assertSame([
            ['start' => 0.0, 'end' => 2.4, 'text' => 'This AI just changed coding'],
        ], $result->segments);

        Process::assertRan(function ($process) {
            return in_array('--audio', $process->command, true)
                && in_array('/tmp/audio.mp3', $process->command, true)
                && in_array('--language', $process->command, true)
                && in_array('en', $process->command, true);
        });
    }

    public function test_it_omits_the_language_flag_when_no_language_is_given(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode(['language' => 'en', 'segments' => []])),
        ]);

        $provider = new WhisperCliTranscriptionProvider;
        $provider->transcribe('/tmp/audio.mp3');

        Process::assertRan(function ($process) {
            return ! in_array('--language', $process->command, true);
        });
    }

    public function test_it_throws_with_the_error_output_when_the_process_fails(): void
    {
        Process::fake([
            '*' => Process::result(errorOutput: 'model not found', exitCode: 1),
        ]);

        $provider = new WhisperCliTranscriptionProvider;

        try {
            $provider->transcribe('/tmp/audio.mp3');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('model not found', $exception->getMessage());
        }
    }

    public function test_it_throws_when_the_output_is_not_valid_json(): void
    {
        Process::fake([
            '*' => Process::result(output: 'not json'),
        ]);

        $provider = new WhisperCliTranscriptionProvider;

        try {
            $provider->transcribe('/tmp/audio.mp3');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('invalid JSON', $exception->getMessage());
        }
    }

    public function test_it_uses_the_configured_python_binary_script_model_and_timeout(): void
    {
        config()->set('whisper.python_binary', 'python3.11');
        config()->set('whisper.script_path', '/opt/whisper/transcribe.py');
        config()->set('whisper.model', 'small');
        config()->set('whisper.timeout', 900);

        Process::fake([
            '*' => Process::result(output: json_encode(['language' => 'en', 'segments' => []])),
        ]);

        $provider = new WhisperCliTranscriptionProvider;
        $provider->transcribe('/tmp/audio.mp3');

        Process::assertRan(function ($process) {
            return $process->command === [
                'python3.11', '/opt/whisper/transcribe.py', '--audio', '/tmp/audio.mp3', '--model', 'small',
            ] && $process->timeout === 900;
        });
    }
}
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `php artisan test --filter=WhisperCliTranscriptionProviderTest`
Expected: FAIL — `Class "App\Domain\Video\Providers\WhisperCliTranscriptionProvider" not found`.

- [ ] **Step 5: Implement `WhisperCliTranscriptionProvider`**

`app/Domain/Video/Providers/WhisperCliTranscriptionProvider.php`:

```php
<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\TranscriptionProviderInterface;
use App\Domain\Video\TranscriptionResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

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

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=WhisperCliTranscriptionProviderTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Create `FakeTranscriptionProvider` and register `TranscriptionServiceProvider`**

`app/Domain/Video/Providers/FakeTranscriptionProvider.php`:

```php
<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\TranscriptionProviderInterface;
use App\Domain\Video\TranscriptionResult;

final class FakeTranscriptionProvider implements TranscriptionProviderInterface
{
    /** @var array<int, array{start: float, end: float, text: string}> */
    private array $segments = [];

    private string $language = 'en';

    public ?string $lastAudioPath = null;

    public ?string $lastLanguageArgument = null;

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
        $this->lastAudioPath = $audioPath;
        $this->lastLanguageArgument = $language;

        return new TranscriptionResult(segments: $this->segments, language: $this->language);
    }
}
```

`app/Providers/TranscriptionServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Domain\Video\Providers\WhisperCliTranscriptionProvider;
use App\Domain\Video\TranscriptionProviderInterface;
use Illuminate\Support\ServiceProvider;

class TranscriptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TranscriptionProviderInterface::class, WhisperCliTranscriptionProvider::class);
    }
}
```

In `bootstrap/providers.php`, add the import and register it alongside `AssetServiceProvider`:

```php
<?php

use App\Providers\AppServiceProvider;
use App\Providers\AssetServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\LlmServiceProvider;
use App\Providers\TranscriptionServiceProvider;
use App\Providers\TtsServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    HorizonServiceProvider::class,
    LlmServiceProvider::class,
    TtsServiceProvider::class,
    AssetServiceProvider::class,
    TranscriptionServiceProvider::class,
];
```

There's no dedicated test for the binding itself — Task 3's service tests exercise it indirectly by binding `FakeTranscriptionProvider` the same way `FakeTtsProvider`/`FakeAssetProvider` are bound elsewhere.

- [ ] **Step 8: Run the full test suite and Pint to confirm nothing broke**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, clean.

- [ ] **Step 9: Commit**

```bash
git add app/Domain/Video/TranscriptionResult.php app/Domain/Video/TranscriptionProviderInterface.php \
  app/Domain/Video/Providers/WhisperCliTranscriptionProvider.php \
  app/Domain/Video/Providers/FakeTranscriptionProvider.php app/Providers/TranscriptionServiceProvider.php \
  bootstrap/providers.php config/whisper.php .env.example scripts/whisper_transcribe.py \
  tests/Unit/Domain/Video/WhisperCliTranscriptionProviderTest.php
git commit -m "Add TranscriptionProviderInterface with WhisperCliTranscriptionProvider and FakeTranscriptionProvider"
```

---

### Task 2: `SrtFormatter`

**Files:**
- Create: `app/Domain/Video/Support/SrtFormatter.php`
- Test: `tests/Unit/Domain/Video/SrtFormatterTest.php`

**Interfaces:**
- Consumes: nothing (pure function).
- Produces: `SrtFormatter::format(array $segments): string` where `$segments` is `array<int, array{start: float, end: float, text: string}>` — consumed by Task 3's `GenerateSubtitlesService`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Domain/Video/SrtFormatterTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Support\SrtFormatter;
use Tests\TestCase;

class SrtFormatterTest extends TestCase
{
    public function test_it_formats_a_single_segment(): void
    {
        $srt = SrtFormatter::format([
            ['start' => 0.0, 'end' => 2.4, 'text' => 'Hello world'],
        ]);

        $this->assertSame("1\n00:00:00,000 --> 00:00:02,400\nHello world\n", $srt);
    }

    public function test_it_formats_multiple_segments_separated_by_a_blank_line(): void
    {
        $srt = SrtFormatter::format([
            ['start' => 0.0, 'end' => 1.0, 'text' => 'One'],
            ['start' => 1.0, 'end' => 2.0, 'text' => 'Two'],
        ]);

        $this->assertSame(
            "1\n00:00:00,000 --> 00:00:01,000\nOne\n\n2\n00:00:01,000 --> 00:00:02,000\nTwo\n",
            $srt
        );
    }

    public function test_it_rolls_over_minutes_and_hours_correctly(): void
    {
        $srt = SrtFormatter::format([
            ['start' => 3661.5, 'end' => 3662.0, 'text' => 'One hour in'],
        ]);

        $this->assertSame("1\n01:01:01,500 --> 01:01:02,000\nOne hour in\n", $srt);
    }

    public function test_it_returns_an_empty_string_for_no_segments(): void
    {
        $this->assertSame('', SrtFormatter::format([]));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SrtFormatterTest`
Expected: FAIL — `Class "App\Domain\Video\Support\SrtFormatter" not found`.

- [ ] **Step 3: Implement `SrtFormatter`**

`app/Domain/Video/Support/SrtFormatter.php`:

```php
<?php

namespace App\Domain\Video\Support;

final class SrtFormatter
{
    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $segments
     */
    public static function format(array $segments): string
    {
        if ($segments === []) {
            return '';
        }

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

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=SrtFormatterTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Run the full test suite and Pint**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, clean.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Video/Support/SrtFormatter.php tests/Unit/Domain/Video/SrtFormatterTest.php
git commit -m "Add SrtFormatter"
```

---

### Task 3: `GenerateSubtitlesService`

**Files:**
- Create: `app/Domain/Video/Services/GenerateSubtitlesService.php`
- Test: `tests/Feature/Domain/Video/GenerateSubtitlesServiceTest.php`

**Interfaces:**
- Consumes: `TranscriptionProviderInterface::transcribe()`, `TranscriptionResult`, `FakeTranscriptionProvider` (Task 1); `SrtFormatter::format()` (Task 2); `Video::$voiceover` (`HasOne` `Voiceover`, Phase 1), `Voiceover::$file_path`; `Video::$contentProject` (`BelongsTo` `ContentProject`), `ContentProject::$language` (required string column, Phase 1).
- Produces: `GenerateSubtitlesService::__construct(TranscriptionProviderInterface $transcriptionProvider)`, `GenerateSubtitlesService::generate(Video $video): array{segments: array<int, array{start: float, end: float, text: string}>, language: string, srt: string}` — consumed by Task 4's `GenerateSubtitlesJob`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Domain/Video/GenerateSubtitlesServiceTest.php`:

```php
<?php

namespace Tests\Feature\Domain\Video;

use App\Domain\Video\Providers\FakeTranscriptionProvider;
use App\Domain\Video\Services\GenerateSubtitlesService;
use App\Domain\Video\TranscriptionProviderInterface;
use App\Domain\Video\TranscriptionResult;
use App\Models\ContentProject;
use App\Models\Video;
use App\Models\Voiceover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class GenerateSubtitlesServiceTest extends TestCase
{
    use RefreshDatabase;

    private function videoWithVoiceover(string $language = 'en'): Video
    {
        $project = ContentProject::factory()->create(['language' => $language]);
        $video = Video::factory()->create(['content_project_id' => $project->id]);
        Voiceover::factory()->create(['video_id' => $video->id, 'file_path' => "projects/{$project->id}/audio/{$video->id}.mp3"]);

        Storage::disk(config('filesystems.default'))->put(
            "projects/{$project->id}/audio/{$video->id}.mp3",
            'fake-audio-bytes'
        );

        return $video->fresh(['voiceover', 'contentProject']);
    }

    public function test_it_returns_segments_language_and_formatted_srt(): void
    {
        Storage::fake(config('filesystems.default'));
        $video = $this->videoWithVoiceover('en');

        $provider = (new FakeTranscriptionProvider)->respondWith(
            [['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']],
            'en'
        );

        $service = new GenerateSubtitlesService($provider);
        $result = $service->generate($video);

        $this->assertSame([['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']], $result['segments']);
        $this->assertSame('en', $result['language']);
        $this->assertStringContainsString('Hi', $result['srt']);
    }

    public function test_it_passes_the_content_projects_language_to_the_provider(): void
    {
        Storage::fake(config('filesystems.default'));
        $video = $this->videoWithVoiceover('uk');

        $provider = new FakeTranscriptionProvider;
        $service = new GenerateSubtitlesService($provider);
        $service->generate($video);

        $this->assertSame('uk', $provider->lastLanguageArgument);
    }

    public function test_it_deletes_the_temporary_audio_file_after_a_successful_transcription(): void
    {
        Storage::fake(config('filesystems.default'));
        $video = $this->videoWithVoiceover();

        $provider = new FakeTranscriptionProvider;
        $service = new GenerateSubtitlesService($provider);
        $service->generate($video);

        $this->assertNotNull($provider->lastAudioPath);
        $this->assertFileDoesNotExist($provider->lastAudioPath);
    }

    public function test_it_deletes_the_temporary_audio_file_even_when_the_provider_throws(): void
    {
        Storage::fake(config('filesystems.default'));
        $video = $this->videoWithVoiceover();

        $throwingProvider = new class implements TranscriptionProviderInterface
        {
            public ?string $capturedPath = null;

            public function transcribe(string $audioPath, ?string $language = null): TranscriptionResult
            {
                $this->capturedPath = $audioPath;

                throw new RuntimeException('boom');
            }
        };

        $service = new GenerateSubtitlesService($throwingProvider);

        try {
            $service->generate($video);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertNotNull($throwingProvider->capturedPath);
        $this->assertFileDoesNotExist($throwingProvider->capturedPath);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=GenerateSubtitlesServiceTest`
Expected: FAIL — `Class "App\Domain\Video\Services\GenerateSubtitlesService" not found`.

- [ ] **Step 3: Implement `GenerateSubtitlesService`**

`app/Domain/Video/Services/GenerateSubtitlesService.php`:

```php
<?php

namespace App\Domain\Video\Services;

use App\Domain\Video\Support\SrtFormatter;
use App\Domain\Video\TranscriptionProviderInterface;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;

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

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=GenerateSubtitlesServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Run the full test suite and Pint**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, clean.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Video/Services/GenerateSubtitlesService.php \
  tests/Feature/Domain/Video/GenerateSubtitlesServiceTest.php
git commit -m "Add GenerateSubtitlesService"
```

---

### Task 4: `videos.subtitle_id`, `Video::subtitle()`, `GenerateSubtitlesJob`

**Files:**
- Create: `database/migrations/2026_09_16_120000_add_subtitle_id_to_videos_table.php`
- Modify: `app/Models/Video.php`
- Create: `app/Jobs/GenerateSubtitlesJob.php`
- Test: `tests/Feature/Jobs/GenerateSubtitlesJobTest.php`

**Interfaces:**
- Consumes: `GenerateSubtitlesService::generate()` (Task 3); `Video::$status`, `VideoStatus::AssetsReady`; `MediaAsset::create()`, `MediaAssetType::Subtitle` (Phase 1).
- Produces: `Video::subtitle(): BelongsTo` (to `MediaAsset`), `videos.subtitle_id` column; `GenerateSubtitlesJob::__construct(int $videoId)`, dispatched as `GenerateSubtitlesJob::dispatch($record->id)` — consumed by Task 5's Filament action.

- [ ] **Step 1: Write the migration**

`database/migrations/2026_09_16_120000_add_subtitle_id_to_videos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->foreignId('subtitle_id')->nullable()->after('thumbnail_path')
                ->constrained('media_assets')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropForeign(['subtitle_id']);
            $table->dropColumn('subtitle_id');
        });
    }
};
```

Run: `php artisan migrate --database=pgsql`
Expected: migration runs cleanly against the existing `videos` table.

- [ ] **Step 2: Add `subtitle_id` to `Video`'s fillable and the `subtitle()` relation**

In `app/Models/Video.php`, update `$fillable` and add the relation and import:

```php
<?php

namespace App\Models;

use App\Models\Enums\VideoStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_project_id', 'content_idea_id', 'script_id', 'title', 'description',
        'status', 'duration', 'width', 'height', 'file_path', 'thumbnail_path',
        'subtitle_id', 'metadata', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => VideoStatus::class,
            'metadata' => 'array',
        ];
    }

    public function contentProject(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class);
    }

    public function contentIdea(): BelongsTo
    {
        return $this->belongsTo(ContentIdea::class);
    }

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(VideoScene::class)->orderBy('order');
    }

    public function voiceover(): HasOne
    {
        return $this->hasOne(Voiceover::class);
    }

    public function subtitle(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'subtitle_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }
}
```

No dedicated test for the relation itself — Task 4's job test and Task 5's Filament test both exercise `subtitle_id`/`subtitle()` indirectly, the same way `VideoDomainModelsTest` already covers `voiceover()`.

- [ ] **Step 3: Write the failing job tests**

`tests/Feature/Jobs/GenerateSubtitlesJobTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Domain\Video\Providers\FakeTranscriptionProvider;
use App\Domain\Video\TranscriptionProviderInterface;
use App\Jobs\GenerateSubtitlesJob;
use App\Models\ContentProject;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\Video;
use App\Models\Voiceover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateSubtitlesJobTest extends TestCase
{
    use RefreshDatabase;

    private function videoReadyForSubtitles(): Video
    {
        $project = ContentProject::factory()->create(['language' => 'en']);
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'status' => VideoStatus::AssetsReady,
        ]);
        Voiceover::factory()->create([
            'video_id' => $video->id,
            'file_path' => "projects/{$project->id}/audio/{$video->id}.mp3",
        ]);

        Storage::disk(config('filesystems.default'))->put(
            "projects/{$project->id}/audio/{$video->id}.mp3",
            'fake-audio-bytes'
        );

        return $video;
    }

    private function bindFakeTranscription(array $segments = [], string $language = 'en'): void
    {
        $this->app->bind(TranscriptionProviderInterface::class, function () use ($segments, $language) {
            return (new FakeTranscriptionProvider)->respondWith($segments, $language);
        });
    }

    public function test_it_creates_a_subtitle_media_asset_and_links_it_to_the_video(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->bindFakeTranscription([['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']], 'en');

        $video = $this->videoReadyForSubtitles();

        app()->call([new GenerateSubtitlesJob($video->id), 'handle']);

        $subtitle = MediaAsset::where('type', MediaAssetType::Subtitle)->sole();
        $this->assertSame('whisper', $subtitle->provider);
        $this->assertSame('application/x-subrip', $subtitle->mime_type);
        $this->assertSame([['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']], $subtitle->metadata['segments']);
        $this->assertSame('en', $subtitle->metadata['language']);

        $expectedPath = "projects/{$video->content_project_id}/subtitles/{$video->id}.srt";
        $this->assertSame($expectedPath, $subtitle->path);
        Storage::disk(config('filesystems.default'))->assertExists($expectedPath);

        $this->assertSame($subtitle->id, $video->fresh()->subtitle_id);
    }

    public function test_it_is_a_no_op_when_the_video_status_is_not_assets_ready(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->bindFakeTranscription();

        $project = ContentProject::factory()->create(['language' => 'en']);
        $video = Video::factory()->create(['content_project_id' => $project->id, 'status' => VideoStatus::VoiceGenerated]);

        app()->call([new GenerateSubtitlesJob($video->id), 'handle']);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertNull($video->fresh()->subtitle_id);
    }

    public function test_it_is_a_no_op_when_a_subtitle_already_exists(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->bindFakeTranscription();

        $video = $this->videoReadyForSubtitles();
        $existing = MediaAsset::factory()->create(['type' => MediaAssetType::Subtitle]);
        $video->update(['subtitle_id' => $existing->id]);

        app()->call([new GenerateSubtitlesJob($video->id), 'handle']);

        $this->assertSame(1, MediaAsset::where('type', MediaAssetType::Subtitle)->count());
        $this->assertSame($existing->id, $video->fresh()->subtitle_id);
    }

    public function test_calling_handle_twice_does_not_create_a_duplicate_subtitle(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->bindFakeTranscription([['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']]);

        $video = $this->videoReadyForSubtitles();

        app()->call([new GenerateSubtitlesJob($video->id), 'handle']);
        app()->call([new GenerateSubtitlesJob($video->id), 'handle']);

        $this->assertSame(1, MediaAsset::where('type', MediaAssetType::Subtitle)->count());
    }
}
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `php artisan test --filter=GenerateSubtitlesJobTest`
Expected: FAIL — `Class "App\Jobs\GenerateSubtitlesJob" not found`.

- [ ] **Step 5: Implement `GenerateSubtitlesJob`**

`app/Jobs/GenerateSubtitlesJob.php`:

```php
<?php

namespace App\Jobs;

use App\Domain\Video\Services\GenerateSubtitlesService;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateSubtitlesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 650;

    public int $tries = 3;

    public int $uniqueFor = 700;

    public function __construct(public readonly int $videoId) {}

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(GenerateSubtitlesService $service): void
    {
        $video = Video::with(['voiceover', 'contentProject'])->findOrFail($this->videoId);

        if ($video->status !== VideoStatus::AssetsReady || $video->subtitle_id !== null) {
            return;
        }

        $result = $service->generate($video);

        $path = "projects/{$video->content_project_id}/subtitles/{$video->id}.srt";
        Storage::disk(config('filesystems.default'))->put($path, $result['srt']);

        DB::transaction(function () use ($video, $result, $path) {
            $subtitle = MediaAsset::create([
                'type' => MediaAssetType::Subtitle,
                'provider' => 'whisper',
                'path' => $path,
                'mime_type' => 'application/x-subrip',
                'metadata' => ['segments' => $result['segments'], 'language' => $result['language']],
                'hash' => hash('sha256', $result['srt']),
            ]);

            $video->update(['subtitle_id' => $subtitle->id]);
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('video')->error('Subtitle generation failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=GenerateSubtitlesJobTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Run the full test suite and Pint**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, clean.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_09_16_120000_add_subtitle_id_to_videos_table.php \
  app/Models/Video.php app/Jobs/GenerateSubtitlesJob.php \
  tests/Feature/Jobs/GenerateSubtitlesJobTest.php
git commit -m "Add GenerateSubtitlesJob with videos.subtitle_id"
```

---

### Task 5: Filament — "Generate Subtitles" row action on `VideosTable`

**Files:**
- Modify: `app/Filament/Resources/Videos/Tables/VideosTable.php`
- Test: `tests/Feature/Filament/VideoGenerateSubtitlesActionTest.php`

**Interfaces:**
- Consumes: `GenerateSubtitlesJob::dispatch(int $videoId)` (Task 4), `VideoStatus::AssetsReady`, `Video::$subtitle_id`.
- Produces: nothing downstream — leaf UI action.

- [ ] **Step 1: Write the failing Filament tests**

`tests/Feature/Filament/VideoGenerateSubtitlesActionTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Jobs\GenerateSubtitlesJob;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class VideoGenerateSubtitlesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_subtitles_action_dispatches_the_job_when_assets_ready_and_no_subtitle_exists(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady]);

        Livewire::test(ListVideos::class)
            ->callTableAction('generateSubtitles', $video)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(GenerateSubtitlesJob::class, fn (GenerateSubtitlesJob $job) => $job->videoId === $video->id);
    }

    public function test_generate_subtitles_action_is_not_visible_before_assets_are_ready(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::VoiceGenerated]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('generateSubtitles', $video);
    }

    public function test_generate_subtitles_action_is_not_visible_once_a_subtitle_already_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $subtitle = MediaAsset::factory()->create(['type' => MediaAssetType::Subtitle]);
        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady, 'subtitle_id' => $subtitle->id]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('generateSubtitles', $video);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=VideoGenerateSubtitlesActionTest`
Expected: FAIL — table action `generateSubtitles` not found.

- [ ] **Step 3: Add the row action to `VideosTable`**

`app/Filament/Resources/Videos/Tables/VideosTable.php`:

```php
<?php

namespace App\Filament\Resources\Videos\Tables;

use App\Jobs\CollectVideoAssetsJob;
use App\Jobs\GenerateSubtitlesJob;
use App\Jobs\GenerateVoiceoverJob;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VideosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contentProject.name')
                    ->searchable(),
                TextColumn::make('contentIdea.title')
                    ->searchable(),
                TextColumn::make('script.id')
                    ->searchable(),
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('duration')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('width')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('height')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('file_path')
                    ->searchable(),
                TextColumn::make('thumbnail_path')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('generateVoiceover')
                    ->label('Generate Voiceover')
                    ->visible(fn (Video $record): bool => $record->status === VideoStatus::ScriptGenerated
                        && ! $record->voiceover()->exists())
                    ->requiresConfirmation()
                    ->action(function (Video $record): void {
                        GenerateVoiceoverJob::dispatch($record->id);

                        Notification::make()->title('Voiceover generation queued')->success()->send();
                    }),
                Action::make('collectAssets')
                    ->label('Collect Assets')
                    ->visible(fn (Video $record): bool => $record->status === VideoStatus::VoiceGenerated)
                    ->requiresConfirmation()
                    ->action(function (Video $record): void {
                        CollectVideoAssetsJob::dispatch($record->id);

                        Notification::make()->title('Asset collection queued')->success()->send();
                    }),
                Action::make('generateSubtitles')
                    ->label('Generate Subtitles')
                    ->visible(fn (Video $record): bool => $record->status === VideoStatus::AssetsReady
                        && $record->subtitle_id === null)
                    ->requiresConfirmation()
                    ->action(function (Video $record): void {
                        GenerateSubtitlesJob::dispatch($record->id);

                        Notification::make()->title('Subtitle generation queued')->success()->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=VideoGenerateSubtitlesActionTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Run the full test suite and Pint**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, clean.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/Videos/Tables/VideosTable.php \
  tests/Feature/Filament/VideoGenerateSubtitlesActionTest.php
git commit -m "Filament: Generate Subtitles row action on Video"
```

---

### Task 6: Docker — Python/faster-whisper in the `worker` image

**Files:**
- Modify: `docker/worker/Dockerfile`
- Modify: `docker-compose.yml`

**Interfaces:**
- Consumes: `scripts/whisper_transcribe.py` (Task 1) — already copied into the image by the existing `COPY . .` line, no Dockerfile change needed for that file itself.
- Produces: a `worker` image with `python3`, `pip`, and `faster-whisper` installed, and a named volume so the downloaded model persists across container restarts — consumed only by whoever runs the real `WhisperCliTranscriptionProvider` outside the test suite (nothing in this codebase's automated tests depends on this task).

This task has **no PHPUnit coverage** — the Fakes from Task 1 mean nothing in the test suite ever shells out to Python. Verification here is a best-effort Docker image build, not a test run.

- [ ] **Step 1: Add Python and `faster-whisper` to `docker/worker/Dockerfile`**

Current file:

```dockerfile
FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        ffmpeg \
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath zip intl pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache

RUN composer install --no-dev --optimize-autoloader --no-interaction

CMD ["php", "artisan", "queue:work", "--sleep=3", "--tries=3"]
```

Change it to:

```dockerfile
FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        ffmpeg \
        python3 \
        python3-pip \
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath zip intl pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && pip3 install --no-cache-dir --break-system-packages faster-whisper \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache

RUN composer install --no-dev --optimize-autoloader --no-interaction

CMD ["php", "artisan", "queue:work", "--sleep=3", "--tries=3"]
```

**Note on `--break-system-packages`:** this flag is required on Debian bookworm (the base of `php:8.4-cli` as of PHP 8.4's official image) to let `pip` install outside a virtualenv, per PEP 668. If the build fails with an "externally-managed-environment" error, this flag is already present and something else is wrong (wrong base image tag, `pip3` not actually installed) — investigate rather than removing the flag. If the build instead fails because `--break-system-packages` is an *unrecognized* flag, the base image's `pip` predates PEP 668 enforcement — in that case remove the flag and retry.

- [ ] **Step 2: Add a named volume for the Whisper model cache in `docker-compose.yml`**

In the `worker` service block, add a second volume entry:

```yaml
  worker:
    build:
      context: .
      dockerfile: docker/worker/Dockerfile
    volumes:
      - .:/var/www/html
      - whisper_models:/root/.cache/huggingface
    env_file: .env
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy
```

And add `whisper_models` to the top-level `volumes:` block at the end of the file:

```yaml
volumes:
  postgres_data:
  whisper_models:
```

- [ ] **Step 3: Attempt a build to verify the Dockerfile is syntactically correct and the install steps succeed**

Run: `docker build -f docker/worker/Dockerfile -t autocontent-worker-whisper-check .`

Expected: the build completes successfully, ending with `faster-whisper` installed. This step can be slow (Python/pip downloads, `faster-whisper`'s dependencies) — let it run to completion if possible.

If the build cannot complete in this environment (no Docker daemon access, network restrictions, or a timeout), that is acceptable — report `DONE_WITH_CONCERNS` with the specific reason the build couldn't be verified, but still commit the Dockerfile/compose changes as written above. Do not guess at a different `apt`/`pip` incantation to work around a build failure that looks environment-specific (e.g. a proxy/network error) — only change the Dockerfile if the failure is clearly about the Dockerfile's own content (wrong package name, wrong flag, syntax error).

- [ ] **Step 4: Commit**

```bash
git add docker/worker/Dockerfile docker-compose.yml
git commit -m "Docker: add Python/faster-whisper to the worker image for subtitle generation"
```

---

### Task 7: Final verification

**Files:**
- None (verification only).

**Interfaces:**
- Consumes: everything from Tasks 1-6.
- Produces: nothing downstream — this is the final gate for Phase 3d.

- [ ] **Step 1: Run the full verification suite**

```bash
php artisan migrate:fresh --seed --database=pgsql
php artisan test
vendor/bin/pint --test
php artisan route:list > /dev/null
```

Expected: every test from Phase 0-3c and Phase 3d Tasks 1-5 passes, Pint clean, `route:list` doesn't error, `migrate:fresh --seed` completes cleanly (Phase 1's `DatabaseSeeder` doesn't create `MediaAsset`/subtitle rows, so the new nullable `videos.subtitle_id` doesn't affect seeding).

- [ ] **Step 2: Manually verify the DoD slice (no real Whisper required for this check — `FakeTranscriptionProvider` covers correctness; this step just confirms the Filament wiring end to end)**

With a `Video` already in `AssetsReady` status (from the Phase 3c flow) that has a `Voiceover`: "Generate Subtitles" appears on the `Video` row in Filament → click it → confirm a `MediaAsset` (type `subtitle`) appears in `MediaAssetResource`'s list with `path` populated, an `.srt` file exists on the configured disk, and `Video.subtitle_id` now points at it. Re-clicking (once the button re-renders as hidden, force a second dispatch via `php artisan tinker` if needed) must not create a second subtitle `MediaAsset`.

If real Whisper credentials/setup are available via the Task 6 Docker image, optionally verify once against a real audio file that the transcription produces plausible timestamps — otherwise note this is unverified with a real provider, consistent with how Phase 3b's ElevenLabs and Phase 3d's Whisper are both "unverified with a real provider" until someone runs them against real infrastructure.
