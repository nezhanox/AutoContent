# Phase 3a — Scenes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From a completed `Script`, a real LLM call produces a `Video` row (the project's first) with an ordered `VideoScene[]` — closing the "Video Scenes" half of Phase 3's DoD (TechnicalTask.md §24 item 6), with `Video` uniquely tied to its `Script` at the database level.

**Architecture:** One new domain service (`app/Domain/Video/Services/GenerateScenesService`) mirrors Phase 2's `GenerateScriptService` exactly — structured JSON output via `LlmManagerInterface`, with the same repair-loop contract. `app/Jobs/GenerateScenesJob` mirrors `GenerateScriptJob`'s idempotency pattern (`ShouldBeUnique` + guard), but adds a genuine database-level unique constraint this time (`videos.script_id`), closing a soft spot the Phase 2 final review flagged. A Filament row action on `ScriptsTable` triggers it.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL, the existing `LlmManagerInterface`/`OpenAiLlmProvider`/`AnthropicLlmProvider`/`FakeLlmProvider` stack (unchanged), `filament/filament` v4.13.

**Spec:** `docs/superpowers/specs/2026-09-12-phase3a-scenes-design.md`

## Global Constraints

- `GenerateScenesService::generate(Script $script, ResolvedLlmTarget $target): array` returns `array<int, array{type: string, duration: int, visual_query: ?string, text: string}>` — plain arrays, no DB writes; the job owns persistence (spec, `GenerateScenesService`).
- Repair-loop: identical contract to `GenerateScriptService` — up to 2 additional LLM calls (3 total) on invalid JSON/schema mismatch before throwing `App\Domain\Video\Exceptions\SceneGenerationFailedException`. Sum of scene `duration` is **not** validated against `Script.estimated_duration` (spec, `GenerateScenesService` — repair-loop і схема).
- `purpose='script'` on every `LlmManagerInterface::complete()` call from this service — no new `purpose='scenes'` config/UI (spec, Скоуп — не входить).
- `videos.script_id` gets a real unique index (new ALTER migration) — not just job-level `ShouldBeUnique` (spec, `Video` — створення, unique index, title/description).
- `Video` created with `title = Script.metadata['title']`, `description = Script.hook`, `status = VideoStatus::ScriptGenerated` — no new `VideoStatus` case added; scenes existing is expressed structurally (`$video->scenes()->exists()`), not by status (spec, same section).
- `GenerateScenesJob`: `$timeout = 180`, `$tries = 3`, `backoff() = [10, 30, 60]`, `ShouldBeUnique` keyed by `script_id` — identical values to `GenerateScriptJob` (spec, `GenerateScenesJob` — ідемпотентність і retry).
- On every `handle()` invocation that proceeds past the guards, existing `VideoScene` rows for the video are deleted and recreated from scratch — never merged/upserted (spec, same section).
- "Generate Scenes" is only ever offered for `Script.status = Completed` AND no `Video` already exists for that script (spec, `Filament`).
- No changes to `VideoResource`/`VideoSceneResource` (Phase 1 auto-generated CRUD stays as-is) — that decision is deferred to Phase 3d (spec, Скоуп — не входить).

---

### Task 1: `GenerateScenesService` — structured output with repair loop

**Files:**
- Create: `app/Domain/Video/Exceptions/SceneGenerationFailedException.php`
- Create: `app/Domain/Video/Services/GenerateScenesService.php`
- Test: `tests/Feature/Domain/Video/GenerateScenesServiceTest.php`

**Interfaces:**
- Consumes: `LlmManagerInterface::complete()` (Phase 1/2, unchanged), `App\Models\Script` (Phase 2, `belongsTo` `ContentIdea` `belongsTo` `ContentProject`), `App\Domain\Llm\ResolvedLlmTarget` (Phase 1), `App\Models\Enums\VideoSceneType` (Phase 1).
- Produces: `App\Domain\Video\Services\GenerateScenesService::generate(Script $script, ResolvedLlmTarget $target): array<int, array{type: string, duration: int, visual_query: ?string, text: string}>`, throwing `App\Domain\Video\Exceptions\SceneGenerationFailedException` after 3 total attempts. Task 2's `GenerateScenesJob` calls this exact signature and iterates the returned array to create `VideoScene` rows.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Domain/Video/GenerateScenesServiceTest.php`:

```php
<?php

namespace Tests\Feature\Domain\Video;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\LlmResponse;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Domain\Llm\ResolvedLlmTarget;
use App\Domain\Video\Exceptions\SceneGenerationFailedException;
use App\Domain\Video\Services\GenerateScenesService;
use App\Models\ContentProject;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateScenesServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_parsed_scenes_on_a_valid_first_response(): void
    {
        $script = Script::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager([
            '{"scenes":[{"type":"hook","duration":3,"visual_query":"a laptop","text":"Hi"},{"type":"cta","duration":2,"visual_query":null,"text":"Follow"}]}',
        ]);

        $service = new GenerateScenesService($manager);
        $result = $service->generate($script, $target);

        $this->assertSame([
            ['type' => 'hook', 'duration' => 3, 'visual_query' => 'a laptop', 'text' => 'Hi'],
            ['type' => 'cta', 'duration' => 2, 'visual_query' => null, 'text' => 'Follow'],
        ], $result);
    }

    public function test_it_repairs_after_one_invalid_response(): void
    {
        $script = Script::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager([
            'not json at all',
            '{"scenes":[{"type":"hook","duration":3,"visual_query":null,"text":"Hi"}]}',
        ]);

        $service = new GenerateScenesService($manager);
        $result = $service->generate($script, $target);

        $this->assertCount(1, $result);
        $this->assertSame('hook', $result[0]['type']);
    }

    public function test_it_throws_after_exhausting_repair_attempts(): void
    {
        $script = Script::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager([
            'not json',
            'still not json',
            'nope',
        ]);

        $service = new GenerateScenesService($manager);

        $this->expectException(SceneGenerationFailedException::class);

        $service->generate($script, $target);
    }

    public function test_it_rejects_a_scene_with_an_invalid_type(): void
    {
        $script = Script::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $invalidType = '{"scenes":[{"type":"not-a-real-type","duration":3,"visual_query":null,"text":"Hi"}]}';

        $manager = $this->queuedLlmManager([$invalidType, $invalidType, $invalidType]);

        $service = new GenerateScenesService($manager);

        $this->expectException(SceneGenerationFailedException::class);

        $service->generate($script, $target);
    }

    public function test_it_rejects_an_empty_scenes_array(): void
    {
        $script = Script::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager(['{"scenes":[]}', '{"scenes":[]}', '{"scenes":[]}']);

        $service = new GenerateScenesService($manager);

        $this->expectException(SceneGenerationFailedException::class);

        $service->generate($script, $target);
    }

    /**
     * @param  array<int, string>  $responses
     */
    private function queuedLlmManager(array $responses): LlmManagerInterface
    {
        return new class($responses) implements LlmManagerInterface
        {
            private int $index = 0;

            /** @param array<int, string> $responses */
            public function __construct(private array $responses) {}

            public function resolve(?ContentProject $project, string $purpose, ?string $providerOverride = null, ?string $modelOverride = null): ResolvedLlmTarget
            {
                throw new \LogicException('Not used in this test.');
            }

            public function complete(?ContentProject $project, string $purpose, array $messages, ?array $responseSchema = null, ?string $providerOverride = null, ?string $modelOverride = null, float $temperature = 0.7, ?int $maxTokens = null): LlmResponse
            {
                $content = $this->responses[$this->index] ?? end($this->responses);
                $this->index++;

                return new LlmResponse(
                    content: $content,
                    provider: 'fake',
                    model: 'fake-model',
                    promptTokens: 10,
                    completionTokens: 5,
                );
            }
        };
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=GenerateScenesServiceTest
```

Expected: FAIL — `App\Domain\Video\Services\GenerateScenesService` and `App\Domain\Video\Exceptions\SceneGenerationFailedException` don't exist yet.

- [ ] **Step 3: Create the exception**

Create `app/Domain/Video/Exceptions/SceneGenerationFailedException.php`:

```php
<?php

namespace App\Domain\Video\Exceptions;

use RuntimeException;

final class SceneGenerationFailedException extends RuntimeException {}
```

- [ ] **Step 4: Implement `GenerateScenesService`**

Create `app/Domain/Video/Services/GenerateScenesService.php`:

```php
<?php

namespace App\Domain\Video\Services;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\ResolvedLlmTarget;
use App\Domain\Video\Exceptions\SceneGenerationFailedException;
use App\Models\Enums\VideoSceneType;
use App\Models\Script;
use InvalidArgumentException;
use JsonException;

final class GenerateScenesService
{
    private const MAX_REPAIR_ATTEMPTS = 2;

    public function __construct(private readonly LlmManagerInterface $llmManager) {}

    /**
     * @return array<int, array{type: string, duration: int, visual_query: ?string, text: string}>
     */
    public function generate(Script $script, ResolvedLlmTarget $target): array
    {
        $project = $script->contentIdea->contentProject;
        $messages = $this->buildMessages($script);
        $lastError = 'unknown validation error';

        for ($attempt = 0; $attempt <= self::MAX_REPAIR_ATTEMPTS; $attempt++) {
            $response = $this->llmManager->complete(
                project: $project,
                purpose: 'script',
                messages: $messages,
                responseSchema: $this->schema(),
                providerOverride: $target->providerName,
                modelOverride: $target->model,
            );

            try {
                return $this->parse($response->content);
            } catch (JsonException|InvalidArgumentException $exception) {
                $lastError = $exception->getMessage();
                $messages[] = ['role' => 'assistant', 'content' => $response->content];
                $messages[] = [
                    'role' => 'user',
                    'content' => "Invalid response: {$lastError}. Reply again with valid JSON matching the schema exactly.",
                ];
            }
        }

        throw new SceneGenerationFailedException($lastError);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(Script $script): array
    {
        $system = 'You are a video editor breaking a script into an ordered list of scenes for a short '
            .'vertical video. Each scene has a type, an approximate duration in seconds, an optional '
            .'visual search query describing what should be shown on screen, and the portion of narration '
            .'text spoken during it. Respond only with JSON matching the given schema — no prose outside the JSON.';

        $user = sprintf(
            "Break this script into scenes.\nTitle: %s\nScript:\n%s",
            $script->metadata['title'] ?? '',
            $script->content,
        );

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * @return array{name: string, schema: array<string, mixed>, strict: bool}
     */
    private function schema(): array
    {
        return [
            'name' => 'video_scenes',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'scenes' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => [
                                    'type' => 'string',
                                    'enum' => array_map(fn (VideoSceneType $case) => $case->value, VideoSceneType::cases()),
                                ],
                                'duration' => ['type' => 'integer'],
                                'visual_query' => ['type' => ['string', 'null']],
                                'text' => ['type' => 'string'],
                            ],
                            'required' => ['type', 'duration', 'visual_query', 'text'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['scenes'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    /**
     * @return array<int, array{type: string, duration: int, visual_query: ?string, text: string}>
     */
    private function parse(string $content): array
    {
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! array_key_exists('scenes', $data) || ! is_array($data['scenes'])) {
            throw new InvalidArgumentException('Response is missing a [scenes] array.');
        }

        if ($data['scenes'] === []) {
            throw new InvalidArgumentException('Field [scenes] must not be empty.');
        }

        $validTypes = array_map(fn (VideoSceneType $case) => $case->value, VideoSceneType::cases());
        $scenes = [];

        foreach ($data['scenes'] as $index => $scene) {
            if (! is_array($scene)) {
                throw new InvalidArgumentException("Scene [{$index}] is not a JSON object.");
            }

            foreach (['type', 'duration', 'visual_query', 'text'] as $field) {
                if (! array_key_exists($field, $scene)) {
                    throw new InvalidArgumentException("Scene [{$index}] is missing required field [{$field}].");
                }
            }

            if (! is_string($scene['type']) || ! in_array($scene['type'], $validTypes, true)) {
                throw new InvalidArgumentException("Scene [{$index}] field [type] must be one of: ".implode(', ', $validTypes).'.');
            }

            if (! is_int($scene['duration'])) {
                throw new InvalidArgumentException("Scene [{$index}] field [duration] must be an integer.");
            }

            if ($scene['visual_query'] !== null && ! is_string($scene['visual_query'])) {
                throw new InvalidArgumentException("Scene [{$index}] field [visual_query] must be a string or null.");
            }

            if (! is_string($scene['text'])) {
                throw new InvalidArgumentException("Scene [{$index}] field [text] must be a string.");
            }

            $scenes[] = [
                'type' => $scene['type'],
                'duration' => $scene['duration'],
                'visual_query' => $scene['visual_query'],
                'text' => $scene['text'],
            ];
        }

        return $scenes;
    }
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --filter=GenerateScenesServiceTest
vendor/bin/pint --test
```

Expected: 5 tests pass, Pint clean.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Video/Exceptions/SceneGenerationFailedException.php app/Domain/Video/Services/GenerateScenesService.php tests/Feature/Domain/Video/GenerateScenesServiceTest.php
git commit -m "Add GenerateScenesService with JSON Schema output and a repair loop"
```

---

### Task 2: `GenerateScenesJob` + unique `videos.script_id` index

**Files:**
- Create: `database/migrations/<timestamp>_add_unique_index_to_videos_script_id.php`
- Create: `app/Jobs/GenerateScenesJob.php`
- Test: `tests/Feature/Jobs/GenerateScenesJobTest.php`

**Interfaces:**
- Consumes: `App\Domain\Video\Services\GenerateScenesService::generate()` (Task 1), `LlmManagerInterface::resolve()` (Phase 1), `App\Models\Script`/`ScriptStatus` (Phase 2), `App\Models\Video`/`VideoScene`/`VideoStatus` (Phase 1).
- Produces: `App\Jobs\GenerateScenesJob` with public constructor `__construct(public readonly int $scriptId)` — Task 3's Filament row action dispatches `GenerateScenesJob::dispatch($record->id)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Jobs/GenerateScenesJobTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Jobs\GenerateScenesJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ScriptStatus;
use App\Models\Enums\VideoStatus;
use App\Models\Script;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateScenesJobTest extends TestCase
{
    use RefreshDatabase;

    private function scriptWithCompletedStatus(): Script
    {
        $project = ContentProject::factory()->create(['settings' => []]);
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);

        return Script::factory()->create([
            'content_idea_id' => $idea->id,
            'status' => ScriptStatus::Completed,
            'metadata' => ['title' => 'My Video Title', 'cta' => 'Follow'],
            'hook' => 'Catchy hook',
        ]);
    }

    private function fakeScenesResponse(): string
    {
        return json_encode([
            'scenes' => [
                ['type' => 'hook', 'duration' => 3, 'visual_query' => 'a laptop', 'text' => 'Hi there'],
                ['type' => 'cta', 'duration' => 2, 'visual_query' => null, 'text' => 'Follow us'],
            ],
        ]);
    }

    public function test_it_creates_a_video_with_ordered_scenes(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith($this->fakeScenesResponse());
        });

        $script = $this->scriptWithCompletedStatus();

        $job = new GenerateScenesJob($script->id);
        app()->call([$job, 'handle']);

        $video = Video::where('script_id', $script->id)->sole();
        $this->assertSame('My Video Title', $video->title);
        $this->assertSame('Catchy hook', $video->description);
        $this->assertSame(VideoStatus::ScriptGenerated, $video->status);

        $scenes = VideoScene::where('video_id', $video->id)->orderBy('order')->get();
        $this->assertCount(2, $scenes);
        $this->assertSame(0, $scenes[0]->order);
        $this->assertSame('hook', $scenes[0]->type->value);
        $this->assertSame(1, $scenes[1]->order);
        $this->assertSame('cta', $scenes[1]->type->value);
    }

    public function test_it_is_a_no_op_when_the_script_is_not_completed(): void
    {
        $project = ContentProject::factory()->create(['settings' => []]);
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);
        $script = Script::factory()->create(['content_idea_id' => $idea->id, 'status' => ScriptStatus::Processing]);

        $job = new GenerateScenesJob($script->id);
        app()->call([$job, 'handle']);

        $this->assertDatabaseCount('videos', 0);
    }

    public function test_calling_handle_twice_does_not_create_a_duplicate_video(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith($this->fakeScenesResponse());
        });

        $script = $this->scriptWithCompletedStatus();

        app()->call([new GenerateScenesJob($script->id), 'handle']);
        app()->call([new GenerateScenesJob($script->id), 'handle']);

        $this->assertSame(1, Video::where('script_id', $script->id)->count());
    }

    public function test_calling_handle_twice_replaces_the_scenes_rather_than_duplicating_them(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith($this->fakeScenesResponse());
        });

        $script = $this->scriptWithCompletedStatus();

        app()->call([new GenerateScenesJob($script->id), 'handle']);
        app()->call([new GenerateScenesJob($script->id), 'handle']);

        $video = Video::where('script_id', $script->id)->sole();
        $this->assertSame(2, VideoScene::where('video_id', $video->id)->count());
    }

    public function test_the_unique_index_prevents_a_second_video_for_the_same_script_at_the_database_level(): void
    {
        $script = $this->scriptWithCompletedStatus();

        Video::factory()->create([
            'content_project_id' => $script->contentIdea->content_project_id,
            'content_idea_id' => $script->content_idea_id,
            'script_id' => $script->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Video::factory()->create([
            'content_project_id' => $script->contentIdea->content_project_id,
            'content_idea_id' => $script->content_idea_id,
            'script_id' => $script->id,
        ]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=GenerateScenesJobTest
```

Expected: FAIL — `App\Jobs\GenerateScenesJob` doesn't exist yet, and the `videos.script_id` column has no unique constraint yet (the last test would fail differently — no exception thrown — once the job exists but before the migration runs).

- [ ] **Step 3: Add the unique index migration**

```bash
php artisan make:migration add_unique_index_to_videos_script_id --table=videos
```

Replace its contents:

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
            $table->dropIndex(['script_id']);
            $table->unique('script_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropUnique(['script_id']);
            $table->index('script_id');
        });
    }
};
```

(The existing plain index from Phase 1's `create_videos_table` migration — `$table->index('script_id')`, conventionally named `videos_script_id_index` — is dropped and replaced by a unique one; a unique index already serves as a lookup index, so keeping both would be redundant.)

- [ ] **Step 4: Implement `GenerateScenesJob`**

Create `app/Jobs/GenerateScenesJob.php`:

```php
<?php

namespace App\Jobs;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Video\Services\GenerateScenesService;
use App\Models\Enums\ScriptStatus;
use App\Models\Enums\VideoStatus;
use App\Models\Script;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateScenesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public int $uniqueFor = 200;

    public function __construct(public readonly int $scriptId) {}

    public function uniqueId(): string
    {
        return (string) $this->scriptId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(LlmManagerInterface $llmManager, GenerateScenesService $service): void
    {
        $script = Script::findOrFail($this->scriptId);

        if ($script->status !== ScriptStatus::Completed) {
            return;
        }

        $idea = $script->contentIdea;
        $target = $llmManager->resolve($idea->contentProject, 'script');

        $video = Video::firstOrCreate(
            ['script_id' => $script->id],
            [
                'content_project_id' => $idea->content_project_id,
                'content_idea_id' => $idea->id,
                'title' => $script->metadata['title'] ?? $idea->title,
                'description' => $script->hook,
                'status' => VideoStatus::ScriptGenerated,
            ]
        );

        $video->scenes()->delete();

        $scenes = $service->generate($script, $target);

        foreach ($scenes as $order => $scene) {
            VideoScene::create([
                'video_id' => $video->id,
                'order' => $order,
                'type' => $scene['type'],
                'duration' => $scene['duration'],
                'text' => $scene['text'],
                'visual_query' => $scene['visual_query'],
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('video')->error('Scene generation failed permanently.', [
            'script_id' => $this->scriptId,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan migrate --database=pgsql
php artisan test --filter=GenerateScenesJobTest
vendor/bin/pint --test
```

Expected: 5 tests pass, Pint clean.

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Jobs/GenerateScenesJob.php tests/Feature/Jobs/GenerateScenesJobTest.php
git commit -m "Add idempotent GenerateScenesJob with a unique videos.script_id index"
```

---

### Task 3: Filament — "Generate Scenes" row action on `ScriptsTable`

**Files:**
- Modify: `app/Filament/Resources/Scripts/Tables/ScriptsTable.php`
- Test: `tests/Feature/Filament/ScriptSceneActionsTest.php`

**Interfaces:**
- Consumes: `GenerateScenesJob::dispatch()` (Task 2), `App\Models\Enums\ScriptStatus` (Phase 2).
- Produces: nothing downstream depends on this — it is the Filament trigger for Phase 3a's DoD slice.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/ScriptSceneActionsTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Scripts\Pages\ListScripts;
use App\Jobs\GenerateScenesJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ScriptStatus;
use App\Models\Script;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ScriptSceneActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_scenes_action_dispatches_the_job_when_completed_and_no_video_exists(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $script = Script::factory()->create(['status' => ScriptStatus::Completed]);

        Livewire::test(ListScripts::class)
            ->callTableAction('generateScenes', $script)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(GenerateScenesJob::class, fn (GenerateScenesJob $job) => $job->scriptId === $script->id);
    }

    public function test_generate_scenes_action_is_not_visible_for_an_incomplete_script(): void
    {
        $this->actingAs(User::factory()->create());

        $script = Script::factory()->create(['status' => ScriptStatus::Processing]);

        Livewire::test(ListScripts::class)
            ->assertTableActionHidden('generateScenes', $script);
    }

    public function test_generate_scenes_action_is_not_visible_once_a_video_already_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create();
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);
        $script = Script::factory()->create(['content_idea_id' => $idea->id, 'status' => ScriptStatus::Completed]);

        Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'script_id' => $script->id,
        ]);

        Livewire::test(ListScripts::class)
            ->assertTableActionHidden('generateScenes', $script);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=ScriptSceneActionsTest
```

Expected: FAIL — the `generateScenes` action doesn't exist on `ScriptsTable` yet.

- [ ] **Step 3: Add the row action**

Replace the whole file `app/Filament/Resources/Scripts/Tables/ScriptsTable.php`:

```php
<?php

namespace App\Filament\Resources\Scripts\Tables;

use App\Jobs\GenerateScenesJob;
use App\Models\Enums\ScriptStatus;
use App\Models\Script;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ScriptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contentIdea.title')
                    ->searchable(),
                TextColumn::make('provider')
                    ->searchable(),
                TextColumn::make('model')
                    ->searchable(),
                TextColumn::make('prompt_version')
                    ->searchable(),
                TextColumn::make('estimated_duration')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
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
                Action::make('generateScenes')
                    ->label('Generate Scenes')
                    ->visible(fn (Script $record): bool => $record->status === ScriptStatus::Completed
                        && ! $record->videos()->exists())
                    ->requiresConfirmation()
                    ->action(function (Script $record): void {
                        GenerateScenesJob::dispatch($record->id);

                        Notification::make()->title('Scene generation queued')->success()->send();
                    }),
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --filter=ScriptSceneActionsTest
vendor/bin/pint --test
```

Expected: 3 tests pass, Pint clean.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/Scripts/Tables/ScriptsTable.php tests/Feature/Filament/ScriptSceneActionsTest.php
git commit -m "Filament: Generate Scenes row action on Script"
```

---

### Task 4: Final verification

**Files:**
- None (verification only).

**Interfaces:**
- Consumes: everything from Tasks 1-3.
- Produces: nothing downstream — this is the final gate for Phase 3a.

- [ ] **Step 1: Run the full verification suite**

```bash
php artisan migrate:fresh --seed --database=pgsql
php artisan test
vendor/bin/pint --test
php artisan route:list > /dev/null
```

Expected: every test from Phase 0-2 and Phase 3a Tasks 1-3 passes, Pint clean, `route:list` doesn't error, `migrate:fresh --seed` completes (Phase 1's `DatabaseSeeder` doesn't create any `Video`/`VideoScene` rows, so the new unique index doesn't affect seeding).

- [ ] **Step 2: Manually verify the DoD slice (not scripted — do this once against a real LLM if credentials are available, otherwise note it's unverified with real providers)**

Create a `ContentProject` → `ContentIdea` → approve it → "Generate Script" → once `Completed`, "Generate Scenes" appears on the `Script` row → click it → confirm a `Video` and its `VideoScene[]` appear in the existing `VideoSceneResource` list, ordered correctly.
