# Phase 3b — Voiceover Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From a `Video` with generated scenes (Phase 3a), a real ElevenLabs TTS call produces a `Voiceover` row — an audio file on disk plus its DB record — closing the "Voiceover" slice of Phase 3's DoD (TechnicalTask.md §24 item 5), with `Voiceover` uniquely tied to its `Video` at the database level.

**Architecture:** A new `TtsProviderInterface` (mirrors `LlmProviderInterface`'s shape but is bound directly to `ElevenLabsTtsProvider` — no manager layer, since there's only one provider in the MVP). `app/Domain/Video/Services/GenerateVoiceoverService` concatenates scene text and resolves the voice, then delegates to the provider — no repair-loop, since there's no JSON structure to validate. `app/Jobs/GenerateVoiceoverJob` mirrors `GenerateScenesJob`'s idempotency pattern (`ShouldBeUnique` + guard + DB-level unique constraint), writes the audio file to disk *before* the DB transaction (external side-effect first, lesson carried over from the Phase 3a review). A Filament row action on `VideosTable` triggers it.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL, Laravel `Http` facade (same retry/timeout idiom as `OpenAiLlmProvider`/`AnthropicLlmProvider`), `filament/filament` v4.13.

**Spec:** `docs/superpowers/specs/2026-09-12-phase3b-voiceover-design.md`

## Global Constraints

- `TtsProviderInterface::generate(string $text, VoiceSettings $settings): VoiceResult` — one method, no manager/registry layer; `TtsServiceProvider` binds it straight to `ElevenLabsTtsProvider` (spec, `TtsProviderInterface`).
- ElevenLabs response is **raw `audio/mpeg` bytes on success, JSON on error** — read success via `$response->body()`, never `json_decode()` it; parse the error body as JSON only in the error branch, with a raw-text fallback if it isn't JSON (spec, `ElevenLabsTtsProvider::generate()`).
- Auth header is `xi-api-key`, not `Authorization: Bearer` (spec, same section).
- `Http::timeout(120)->retry(3, 500, when: ...)` — retries only `ConnectionException` and 5xx `RequestException`, identical semantics to `OpenAiLlmProvider`/`AnthropicLlmProvider`, all failures collapse to `\RuntimeException` (spec, same section).
- `config/tts.php` is a new, dedicated config file (same pattern as `config/llm.php`); `.env.example` gets `ELEVENLABS_BASE_URL`, `ELEVENLABS_MODEL_ID`, `ELEVENLABS_DEFAULT_VOICE` (no secrets) — `ELEVENLABS_API_KEY` already exists in `.env.example` (spec, `config/tts.php`).
- `GenerateVoiceoverService::generate(Video $video): array` returns `array{text: string, audio: string, provider: string, voice: string, metadata: array<string, mixed>}` — no DB writes, no repair-loop; the job owns persistence (spec, `GenerateVoiceoverService`).
- Voice resolution order: `$video->contentProject->settings['tts']['voice'] ?? config('tts.default_voice')`; if neither is set, throw `\InvalidArgumentException` **before** calling the provider (spec, `resolveVoiceSettings`).
- `buildText()` concatenates `VideoScene.text` (already ordered by `order` via the `Video::scenes()` relation) with a single space (spec, same section).
- `voiceovers.video_id` gets a real unique index (new ALTER migration), and `Voiceover.status` casts to a new `VoiceoverStatus` enum (`Pending`/`Processing`/`Completed`/`Failed`) instead of a plain string — the job always writes `Completed` in 3b (spec, `Ідемпотентність`).
- `GenerateVoiceoverJob`: `$timeout = 180`, `$tries = 3`, `backoff() = [10, 30, 60]`, `ShouldBeUnique` keyed by `video_id`, `uniqueFor = 200` — identical values to `GenerateScenesJob` (spec, `GenerateVoiceoverJob`).
- `handle()` order: guard (`status !== ScriptGenerated` OR `voiceover()->exists()` → no-op) → TTS call → **write the audio file to disk** → `DB::transaction()` creating `Voiceover` and updating `Video.status = VoiceGenerated` together (spec, same section — external side-effects before the DB transaction, never inside it).
- File path: `projects/{video->content_project_id}/audio/{video->id}.mp3` on `Storage::disk(config('filesystems.default'))` (spec, same section).
- `failed()` only logs to the `video` channel — no DB state change, no separate "failed" status on `Video` (spec, same section).
- `Voiceover.duration` stays `null` after 3b — out of scope, no migration needed (spec, Скоуп — не входить).
- "Generate Voiceover" row action on `VideosTable` only, visible when `Video.status === ScriptGenerated` AND no `Voiceover` exists yet (spec, `Filament`).
- No changes to `VideoResource`/`VideoSceneResource`/`VoiceoverResource` (auto-generated CRUD stays as-is) — deferred, per spec (spec, Скоуп — не входить).

---

### Task 1: TTS provider contracts — `TtsProviderInterface`, `ElevenLabsTtsProvider`, `FakeTtsProvider`

**Files:**
- Create: `app/Domain/Video/VoiceSettings.php`
- Create: `app/Domain/Video/VoiceResult.php`
- Create: `app/Domain/Video/TtsProviderInterface.php`
- Create: `app/Domain/Video/Providers/ElevenLabsTtsProvider.php`
- Create: `app/Domain/Video/Providers/FakeTtsProvider.php`
- Create: `app/Providers/TtsServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Create: `config/tts.php`
- Modify: `.env.example`
- Test: `tests/Unit/Domain/Video/ElevenLabsTtsProviderTest.php`

**Interfaces:**
- Consumes: nothing (this is the bottom of the dependency chain).
- Produces: `TtsProviderInterface::generate(string $text, VoiceSettings $settings): VoiceResult`, `VoiceSettings::__construct(string $voiceId, float $stability = 0.5, float $similarityBoost = 0.75)`, `VoiceResult::__construct(string $audioContent, string $provider, string $voice, array $metadata = [])` — consumed by Task 2's `GenerateVoiceoverService` and by `FakeTtsProvider` in every later test.

- [ ] **Step 1: Create the DTOs and the interface**

`app/Domain/Video/VoiceSettings.php`:

```php
<?php

namespace App\Domain\Video;

final class VoiceSettings
{
    public function __construct(
        public readonly string $voiceId,
        public readonly float $stability = 0.5,
        public readonly float $similarityBoost = 0.75,
    ) {}
}
```

`app/Domain/Video/VoiceResult.php`:

```php
<?php

namespace App\Domain\Video;

final class VoiceResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $audioContent,
        public readonly string $provider,
        public readonly string $voice,
        public readonly array $metadata = [],
    ) {}
}
```

`app/Domain/Video/TtsProviderInterface.php`:

```php
<?php

namespace App\Domain\Video;

interface TtsProviderInterface
{
    public function generate(string $text, VoiceSettings $settings): VoiceResult;
}
```

There is no test to run for this step — these are plain value types with no behavior. Proceed straight to Step 2.

- [ ] **Step 2: Create `config/tts.php` and add `.env.example` entries**

`config/tts.php`:

```php
<?php

return [
    'default_voice' => env('ELEVENLABS_DEFAULT_VOICE'),
    'providers' => [
        'elevenlabs' => [
            'api_key' => env('ELEVENLABS_API_KEY'),
            'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io/v1'),
            'model_id' => env('ELEVENLABS_MODEL_ID', 'eleven_multilingual_v2'),
        ],
    ],
];
```

In `.env.example`, the `ELEVENLABS_API_KEY=` line already exists (added in an earlier phase). Replace it with:

```
ELEVENLABS_API_KEY=
ELEVENLABS_BASE_URL=https://api.elevenlabs.io/v1
ELEVENLABS_MODEL_ID=eleven_multilingual_v2
ELEVENLABS_DEFAULT_VOICE=
```

- [ ] **Step 3: Write the failing test for a successful ElevenLabs call**

`tests/Unit/Domain/Video/ElevenLabsTtsProviderTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Providers\ElevenLabsTtsProvider;
use App\Domain\Video\VoiceSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ElevenLabsTtsProviderTest extends TestCase
{
    private function configure(): void
    {
        config()->set('tts.providers.elevenlabs.api_key', 'test-key');
        config()->set('tts.providers.elevenlabs.base_url', 'https://api.elevenlabs.io/v1');
        config()->set('tts.providers.elevenlabs.model_id', 'eleven_multilingual_v2');
    }

    public function test_it_returns_raw_audio_bytes_on_a_successful_response(): void
    {
        $this->configure();

        Http::fake([
            'api.elevenlabs.io/*' => Http::response('raw-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $provider = new ElevenLabsTtsProvider;
        $result = $provider->generate('Hello world', new VoiceSettings(voiceId: 'adam'));

        $this->assertSame('raw-mp3-bytes', $result->audioContent);
        $this->assertSame('elevenlabs', $result->provider);
        $this->assertSame('adam', $result->voice);
        $this->assertSame('eleven_multilingual_v2', $result->metadata['model_id']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/adam'
                && $request->hasHeader('xi-api-key', 'test-key')
                && ! $request->hasHeader('Authorization')
                && $request['text'] === 'Hello world'
                && $request['model_id'] === 'eleven_multilingual_v2'
                && $request['voice_settings'] === ['stability' => 0.5, 'similarity_boost' => 0.75];
        });
    }

    public function test_it_throws_with_the_elevenlabs_error_detail_on_a_client_error(): void
    {
        $this->configure();

        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => ['message' => 'Invalid voice_id']], 400),
        ]);

        $provider = new ElevenLabsTtsProvider;

        try {
            $provider->generate('Hello', new VoiceSettings(voiceId: 'bad-voice'));
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Invalid voice_id', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_it_falls_back_to_the_raw_body_when_the_error_is_not_json(): void
    {
        $this->configure();

        Http::fake([
            'api.elevenlabs.io/*' => Http::response('upstream gateway timeout', 400),
        ]);

        $provider = new ElevenLabsTtsProvider;

        try {
            $provider->generate('Hello', new VoiceSettings(voiceId: 'adam'));
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('upstream gateway timeout', $exception->getMessage());
        }
    }

    public function test_it_retries_on_server_errors_and_eventually_succeeds(): void
    {
        $this->configure();

        Sleep::fake();

        Http::fake([
            'api.elevenlabs.io/*' => Http::sequence()
                ->push(['detail' => 'overloaded'], 503)
                ->push(['detail' => 'overloaded'], 503)
                ->push('raw-mp3-bytes', 200),
        ]);

        $provider = new ElevenLabsTtsProvider;
        $result = $provider->generate('Hello', new VoiceSettings(voiceId: 'adam'));

        $this->assertSame('raw-mp3-bytes', $result->audioContent);
        Http::assertSentCount(3);
    }

    public function test_it_does_not_retry_on_a_client_error(): void
    {
        $this->configure();

        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => 'bad request'], 400),
        ]);

        $provider = new ElevenLabsTtsProvider;

        try {
            $provider->generate('Hello', new VoiceSettings(voiceId: 'adam'));
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException) {
            // expected
        }

        Http::assertSentCount(1);
    }
}
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `php artisan test --filter=ElevenLabsTtsProviderTest`
Expected: FAIL — `Class "App\Domain\Video\Providers\ElevenLabsTtsProvider" not found`.

- [ ] **Step 5: Implement `ElevenLabsTtsProvider`**

`app/Domain/Video/Providers/ElevenLabsTtsProvider.php`:

```php
<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\TtsProviderInterface;
use App\Domain\Video\VoiceResult;
use App\Domain\Video\VoiceSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ElevenLabsTtsProvider implements TtsProviderInterface
{
    public function generate(string $text, VoiceSettings $settings): VoiceResult
    {
        $modelId = config('tts.providers.elevenlabs.model_id');

        try {
            $response = Http::withHeaders([
                'xi-api-key' => config('tts.providers.elevenlabs.api_key'),
            ])
                ->baseUrl(config('tts.providers.elevenlabs.base_url'))
                ->timeout(120)
                ->retry(3, 500, when: fn ($exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()))
                ->post("/text-to-speech/{$settings->voiceId}", [
                    'text' => $text,
                    'model_id' => $modelId,
                    'voice_settings' => [
                        'stability' => $settings->stability,
                        'similarity_boost' => $settings->similarityBoost,
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'ElevenLabs request failed: '.$exception->getMessage(), previous: $exception
            );
        } catch (RequestException $exception) {
            throw new RuntimeException(
                'ElevenLabs request failed: '.$this->errorMessage($exception->response->body()),
                previous: $exception,
            );
        }

        return new VoiceResult(
            audioContent: $response->body(),
            provider: 'elevenlabs',
            voice: $settings->voiceId,
            metadata: ['model_id' => $modelId],
        );
    }

    private function errorMessage(string $body): string
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! array_key_exists('detail', $decoded)) {
            return $body;
        }

        $detail = $decoded['detail'];

        if (is_array($detail)) {
            return $detail['message'] ?? json_encode($detail);
        }

        return (string) $detail;
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=ElevenLabsTtsProviderTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Create `FakeTtsProvider` and register `TtsServiceProvider`**

`app/Domain/Video/Providers/FakeTtsProvider.php`:

```php
<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\TtsProviderInterface;
use App\Domain\Video\VoiceResult;
use App\Domain\Video\VoiceSettings;

class FakeTtsProvider implements TtsProviderInterface
{
    private string $audioContent = 'fake-audio-bytes';

    private string $provider = 'fake';

    /** @var array<string, mixed> */
    private array $metadata = [];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function respondWith(string $audioContent, string $provider = 'fake', array $metadata = []): static
    {
        $this->audioContent = $audioContent;
        $this->provider = $provider;
        $this->metadata = $metadata;

        return $this;
    }

    public function generate(string $text, VoiceSettings $settings): VoiceResult
    {
        return new VoiceResult(
            audioContent: $this->audioContent,
            provider: $this->provider,
            voice: $settings->voiceId,
            metadata: $this->metadata,
        );
    }
}
```

`app/Providers/TtsServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Domain\Video\Providers\ElevenLabsTtsProvider;
use App\Domain\Video\TtsProviderInterface;
use Illuminate\Support\ServiceProvider;

class TtsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TtsProviderInterface::class, ElevenLabsTtsProvider::class);
    }
}
```

In `bootstrap/providers.php`, add the import and register it alongside `LlmServiceProvider`:

```php
<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\LlmServiceProvider;
use App\Providers\TtsServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    HorizonServiceProvider::class,
    LlmServiceProvider::class,
    TtsServiceProvider::class,
];
```

There's no dedicated test for the binding itself — Task 2's service test exercises it indirectly by binding `FakeTtsProvider` the same way `FakeLlmProvider` is bound in `GenerateScenesJobTest`.

- [ ] **Step 8: Run the full test suite and Pint to confirm nothing broke**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, clean.

- [ ] **Step 9: Commit**

```bash
git add app/Domain/Video/VoiceSettings.php app/Domain/Video/VoiceResult.php \
  app/Domain/Video/TtsProviderInterface.php app/Domain/Video/Providers/ElevenLabsTtsProvider.php \
  app/Domain/Video/Providers/FakeTtsProvider.php app/Providers/TtsServiceProvider.php \
  bootstrap/providers.php config/tts.php .env.example \
  tests/Unit/Domain/Video/ElevenLabsTtsProviderTest.php
git commit -m "Add TtsProviderInterface with ElevenLabsTtsProvider and FakeTtsProvider"
```

---

### Task 2: `GenerateVoiceoverService`

**Files:**
- Create: `app/Domain/Video/Services/GenerateVoiceoverService.php`
- Test: `tests/Feature/Domain/Video/GenerateVoiceoverServiceTest.php`

**Interfaces:**
- Consumes: `TtsProviderInterface::generate()`, `VoiceSettings`, `VoiceResult` (Task 1), `Video::$scenes` (ordered `HasMany` of `VideoScene`, `VideoScene::$text`), `Video::$contentProject` (`BelongsTo` `ContentProject`, `ContentProject::$settings` array cast).
- Produces: `GenerateVoiceoverService::__construct(TtsProviderInterface $ttsProvider)`, `GenerateVoiceoverService::generate(Video $video): array{text: string, audio: string, provider: string, voice: string, metadata: array<string, mixed>}` — consumed by Task 3's `GenerateVoiceoverJob`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Domain/Video/GenerateVoiceoverServiceTest.php`:

```php
<?php

namespace Tests\Feature\Domain\Video;

use App\Domain\Video\Providers\FakeTtsProvider;
use App\Domain\Video\Services\GenerateVoiceoverService;
use App\Models\ContentProject;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class GenerateVoiceoverServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_concatenates_scene_text_in_order_and_uses_the_project_voice(): void
    {
        $project = ContentProject::factory()->create(['settings' => ['tts' => ['voice' => 'rachel']]]);
        $video = Video::factory()->create(['content_project_id' => $project->id]);

        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 1, 'text' => 'second']);
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 0, 'text' => 'first']);

        $tts = (new FakeTtsProvider)->respondWith('audio-bytes', 'fake', ['duration_hint' => 5]);
        $service = new GenerateVoiceoverService($tts);

        $result = $service->generate($video->fresh(['scenes'])->load('contentProject'));

        $this->assertSame('first second', $result['text']);
        $this->assertSame('audio-bytes', $result['audio']);
        $this->assertSame('fake', $result['provider']);
        $this->assertSame('rachel', $result['voice']);
        $this->assertSame(['duration_hint' => 5], $result['metadata']);
    }

    public function test_it_falls_back_to_the_config_default_voice_when_the_project_has_none(): void
    {
        config()->set('tts.default_voice', 'adam');

        $project = ContentProject::factory()->create(['settings' => []]);
        $video = Video::factory()->create(['content_project_id' => $project->id]);
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 0, 'text' => 'hi']);

        $tts = (new FakeTtsProvider)->respondWith('audio-bytes');
        $service = new GenerateVoiceoverService($tts);

        $result = $service->generate($video->fresh(['scenes'])->load('contentProject'));

        $this->assertSame('adam', $result['voice']);
    }

    public function test_it_prefers_the_project_voice_over_the_config_default(): void
    {
        config()->set('tts.default_voice', 'adam');

        $project = ContentProject::factory()->create(['settings' => ['tts' => ['voice' => 'bella']]]);
        $video = Video::factory()->create(['content_project_id' => $project->id]);
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 0, 'text' => 'hi']);

        $tts = (new FakeTtsProvider)->respondWith('audio-bytes');
        $service = new GenerateVoiceoverService($tts);

        $result = $service->generate($video->fresh(['scenes'])->load('contentProject'));

        $this->assertSame('bella', $result['voice']);
    }

    public function test_it_throws_when_no_voice_is_configured_anywhere(): void
    {
        config()->set('tts.default_voice', null);

        $project = ContentProject::factory()->create(['settings' => []]);
        $video = Video::factory()->create(['content_project_id' => $project->id]);
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 0, 'text' => 'hi']);

        $service = new GenerateVoiceoverService(new FakeTtsProvider);

        $this->expectException(InvalidArgumentException::class);

        $service->generate($video->fresh(['scenes'])->load('contentProject'));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=GenerateVoiceoverServiceTest`
Expected: FAIL — `Class "App\Domain\Video\Services\GenerateVoiceoverService" not found`.

- [ ] **Step 3: Implement `GenerateVoiceoverService`**

`app/Domain/Video/Services/GenerateVoiceoverService.php`:

```php
<?php

namespace App\Domain\Video\Services;

use App\Domain\Video\TtsProviderInterface;
use App\Domain\Video\VoiceSettings;
use App\Models\Video;
use InvalidArgumentException;

final class GenerateVoiceoverService
{
    public function __construct(private readonly TtsProviderInterface $ttsProvider) {}

    /**
     * @return array{text: string, audio: string, provider: string, voice: string, metadata: array<string, mixed>}
     */
    public function generate(Video $video): array
    {
        $text = $this->buildText($video);
        $settings = $this->resolveVoiceSettings($video);
        $result = $this->ttsProvider->generate($text, $settings);

        return [
            'text' => $text,
            'audio' => $result->audioContent,
            'provider' => $result->provider,
            'voice' => $result->voice,
            'metadata' => $result->metadata,
        ];
    }

    private function buildText(Video $video): string
    {
        return $video->scenes->pluck('text')->implode(' ');
    }

    private function resolveVoiceSettings(Video $video): VoiceSettings
    {
        $voiceId = $video->contentProject->settings['tts']['voice'] ?? config('tts.default_voice');

        if (! is_string($voiceId) || $voiceId === '') {
            throw new InvalidArgumentException(
                'No TTS voice configured for this video (set ContentProject.settings[tts][voice] or ELEVENLABS_DEFAULT_VOICE).'
            );
        }

        return new VoiceSettings(voiceId: $voiceId);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=GenerateVoiceoverServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Run the full test suite and Pint**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, clean.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Video/Services/GenerateVoiceoverService.php \
  tests/Feature/Domain/Video/GenerateVoiceoverServiceTest.php
git commit -m "Add GenerateVoiceoverService"
```

---

### Task 3: `VoiceoverStatus` enum, unique `voiceovers.video_id` index, `GenerateVoiceoverJob`

**Files:**
- Create: `app/Models/Enums/VoiceoverStatus.php`
- Modify: `app/Models/Voiceover.php`
- Create: `database/migrations/2026_09_12_190000_add_unique_index_to_voiceovers_video_id.php`
- Create: `app/Jobs/GenerateVoiceoverJob.php`
- Test: `tests/Feature/Jobs/GenerateVoiceoverJobTest.php`

**Interfaces:**
- Consumes: `GenerateVoiceoverService::generate()` (Task 2), `Video` (`status`, `content_project_id`, `voiceover()` `HasOne`), `Voiceover::create()`, `VideoStatus::ScriptGenerated`/`VideoStatus::VoiceGenerated`.
- Produces: `GenerateVoiceoverJob::__construct(int $videoId)`, dispatched as `GenerateVoiceoverJob::dispatch($record->id)` — consumed by Task 4's Filament action.

- [ ] **Step 1: Create the `VoiceoverStatus` enum**

`app/Models/Enums/VoiceoverStatus.php`:

```php
<?php

namespace App\Models\Enums;

enum VoiceoverStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
```

- [ ] **Step 2: Cast `Voiceover.status` to the enum**

In `app/Models/Voiceover.php`, add the import and the cast:

```php
<?php

namespace App\Models;

use App\Models\Enums\VoiceoverStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voiceover extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_id', 'provider', 'voice', 'text', 'file_path', 'duration',
        'metadata', 'status',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'status' => VoiceoverStatus::class,
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
```

- [ ] **Step 3: Write the unique-index migration**

`database/migrations/2026_09_12_190000_add_unique_index_to_voiceovers_video_id.php`:

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
        Schema::table('voiceovers', function (Blueprint $table) {
            $table->dropIndex(['video_id']);
            $table->unique('video_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('voiceovers', function (Blueprint $table) {
            $table->dropUnique(['video_id']);
            $table->index('video_id');
        });
    }
};
```

Run: `php artisan migrate --database=pgsql`
Expected: migration runs cleanly against the existing `voiceovers` table.

- [ ] **Step 4: Write the failing job tests**

`tests/Feature/Jobs/GenerateVoiceoverJobTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Domain\Video\Providers\FakeTtsProvider;
use App\Domain\Video\TtsProviderInterface;
use App\Jobs\GenerateVoiceoverJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\VideoStatus;
use App\Models\Enums\VoiceoverStatus;
use App\Models\Video;
use App\Models\VideoScene;
use App\Models\Voiceover;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateVoiceoverJobTest extends TestCase
{
    use RefreshDatabase;

    private function videoWithScenesReadyForVoiceover(): Video
    {
        $project = ContentProject::factory()->create(['settings' => ['tts' => ['voice' => 'adam']]]);
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);

        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'status' => VideoStatus::ScriptGenerated,
        ]);

        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 0, 'text' => 'Hello']);
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 1, 'text' => 'world']);

        return $video;
    }

    private function bindFakeTts(string $audio = 'audio-bytes'): void
    {
        $this->app->bind(TtsProviderInterface::class, function () use ($audio) {
            return (new FakeTtsProvider)->respondWith($audio, 'elevenlabs', ['model_id' => 'eleven_multilingual_v2']);
        });
    }

    public function test_it_creates_a_voiceover_and_writes_the_audio_file(): void
    {
        Storage::fake('local');
        $this->bindFakeTts();

        $video = $this->videoWithScenesReadyForVoiceover();

        app()->call([new GenerateVoiceoverJob($video->id), 'handle']);

        $voiceover = Voiceover::where('video_id', $video->id)->sole();
        $this->assertSame('elevenlabs', $voiceover->provider);
        $this->assertSame('adam', $voiceover->voice);
        $this->assertSame('Hello world', $voiceover->text);
        $this->assertSame(VoiceoverStatus::Completed, $voiceover->status);
        $this->assertSame("projects/{$video->content_project_id}/audio/{$video->id}.mp3", $voiceover->file_path);

        Storage::disk('local')->assertExists($voiceover->file_path);
        $this->assertSame('audio-bytes', Storage::disk('local')->get($voiceover->file_path));

        $this->assertSame(VideoStatus::VoiceGenerated, $video->fresh()->status);
    }

    public function test_it_is_a_no_op_when_the_video_status_is_not_script_generated(): void
    {
        Storage::fake('local');
        $this->bindFakeTts();

        $project = ContentProject::factory()->create(['settings' => ['tts' => ['voice' => 'adam']]]);
        $video = Video::factory()->create(['content_project_id' => $project->id, 'status' => VideoStatus::Draft]);

        app()->call([new GenerateVoiceoverJob($video->id), 'handle']);

        $this->assertDatabaseCount('voiceovers', 0);
    }

    public function test_it_is_a_no_op_when_a_voiceover_already_exists(): void
    {
        Storage::fake('local');
        $this->bindFakeTts();

        $video = $this->videoWithScenesReadyForVoiceover();
        Voiceover::factory()->create(['video_id' => $video->id, 'status' => 'completed']);

        app()->call([new GenerateVoiceoverJob($video->id), 'handle']);

        $this->assertSame(1, Voiceover::where('video_id', $video->id)->count());
    }

    public function test_calling_handle_twice_does_not_create_a_duplicate_voiceover(): void
    {
        Storage::fake('local');
        $this->bindFakeTts();

        $video = $this->videoWithScenesReadyForVoiceover();

        app()->call([new GenerateVoiceoverJob($video->id), 'handle']);
        app()->call([new GenerateVoiceoverJob($video->id), 'handle']);

        $this->assertSame(1, Voiceover::where('video_id', $video->id)->count());
    }

    public function test_the_unique_index_prevents_a_second_voiceover_for_the_same_video_at_the_database_level(): void
    {
        $video = $this->videoWithScenesReadyForVoiceover();

        Voiceover::factory()->create(['video_id' => $video->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        Voiceover::factory()->create(['video_id' => $video->id]);
    }
}
```

- [ ] **Step 5: Run the tests to verify they fail**

Run: `php artisan test --filter=GenerateVoiceoverJobTest`
Expected: FAIL — `Class "App\Jobs\GenerateVoiceoverJob" not found`.

- [ ] **Step 6: Implement `GenerateVoiceoverJob`**

`app/Jobs/GenerateVoiceoverJob.php`:

```php
<?php

namespace App\Jobs;

use App\Domain\Video\Services\GenerateVoiceoverService;
use App\Models\Enums\VideoStatus;
use App\Models\Enums\VoiceoverStatus;
use App\Models\Video;
use App\Models\Voiceover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateVoiceoverJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public int $uniqueFor = 200;

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

    public function handle(GenerateVoiceoverService $service): void
    {
        $video = Video::with('scenes')->findOrFail($this->videoId);

        if ($video->status !== VideoStatus::ScriptGenerated || $video->voiceover()->exists()) {
            return;
        }

        $result = $service->generate($video);

        $path = "projects/{$video->content_project_id}/audio/{$video->id}.mp3";
        Storage::disk(config('filesystems.default'))->put($path, $result['audio']);

        DB::transaction(function () use ($video, $result, $path) {
            Voiceover::create([
                'video_id' => $video->id,
                'provider' => $result['provider'],
                'voice' => $result['voice'],
                'text' => $result['text'],
                'file_path' => $path,
                'metadata' => $result['metadata'],
                'status' => VoiceoverStatus::Completed,
            ]);

            $video->update(['status' => VideoStatus::VoiceGenerated]);
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('video')->error('Voiceover generation failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=GenerateVoiceoverJobTest`
Expected: PASS (5 tests).

- [ ] **Step 8: Run the full test suite and Pint**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, clean.

- [ ] **Step 9: Commit**

```bash
git add app/Models/Enums/VoiceoverStatus.php app/Models/Voiceover.php \
  database/migrations/2026_09_12_190000_add_unique_index_to_voiceovers_video_id.php \
  app/Jobs/GenerateVoiceoverJob.php tests/Feature/Jobs/GenerateVoiceoverJobTest.php
git commit -m "Add GenerateVoiceoverJob with unique voiceovers.video_id index"
```

---

### Task 4: Filament — "Generate Voiceover" row action on `VideosTable`

**Files:**
- Modify: `app/Filament/Resources/Videos/Tables/VideosTable.php`
- Test: `tests/Feature/Filament/VideoVoiceoverActionsTest.php`

**Interfaces:**
- Consumes: `GenerateVoiceoverJob::dispatch(int $videoId)` (Task 3), `VideoStatus::ScriptGenerated`, `Video::voiceover()`.
- Produces: nothing downstream — this is a leaf UI action.

- [ ] **Step 1: Write the failing Filament tests**

`tests/Feature/Filament/VideoVoiceoverActionsTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Jobs\GenerateVoiceoverJob;
use App\Models\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Models\Voiceover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class VideoVoiceoverActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_voiceover_action_dispatches_the_job_when_script_generated_and_no_voiceover_exists(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $video = Video::factory()->create(['status' => VideoStatus::ScriptGenerated]);

        Livewire::test(ListVideos::class)
            ->callTableAction('generateVoiceover', $video)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(GenerateVoiceoverJob::class, fn (GenerateVoiceoverJob $job) => $job->videoId === $video->id);
    }

    public function test_generate_voiceover_action_is_not_visible_before_scenes_are_generated(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::Draft]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('generateVoiceover', $video);
    }

    public function test_generate_voiceover_action_is_not_visible_once_a_voiceover_already_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::ScriptGenerated]);
        Voiceover::factory()->create(['video_id' => $video->id]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('generateVoiceover', $video);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=VideoVoiceoverActionsTest`
Expected: FAIL — table action `generateVoiceover` not found.

- [ ] **Step 3: Add the row action to `VideosTable`**

`app/Filament/Resources/Videos/Tables/VideosTable.php`:

```php
<?php

namespace App\Filament\Resources\Videos\Tables;

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

Run: `php artisan test --filter=VideoVoiceoverActionsTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Run the full test suite and Pint**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, clean.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/Videos/Tables/VideosTable.php \
  tests/Feature/Filament/VideoVoiceoverActionsTest.php
git commit -m "Filament: Generate Voiceover row action on Video"
```

---

### Task 5: Final verification

**Files:**
- None (verification only).

**Interfaces:**
- Consumes: everything from Tasks 1-4.
- Produces: nothing downstream — this is the final gate for Phase 3b.

- [ ] **Step 1: Run the full verification suite**

```bash
php artisan migrate:fresh --seed --database=pgsql
php artisan test
vendor/bin/pint --test
php artisan route:list > /dev/null
```

Expected: every test from Phase 0-3a and Phase 3b Tasks 1-4 passes, Pint clean, `route:list` doesn't error, `migrate:fresh --seed` completes cleanly (Phase 1's `DatabaseSeeder` doesn't create `Voiceover` rows, so the new unique index doesn't affect seeding).

- [ ] **Step 2: Manually verify the DoD slice (not scripted — do this once against real ElevenLabs credentials if available, otherwise note it's unverified with a real provider)**

With a `Video` already in `ScriptGenerated` status (from the Phase 3a flow) whose `ContentProject.settings['tts']['voice']` or `ELEVENLABS_DEFAULT_VOICE` is set: "Generate Voiceover" appears on the `Video` row in Filament → click it → confirm a `Voiceover` row appears (via `VoiceoverResource`'s list) with `file_path` populated, an audio file exists on the configured disk, and `Video.status` is now `VoiceGenerated`. Re-clicking (once the button re-renders as hidden, force a second dispatch via `php artisan tinker` if needed) must not create a second `Voiceover` — verify the DB-level unique constraint holds.
