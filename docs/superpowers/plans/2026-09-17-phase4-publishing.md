# Phase 4 — Publishing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From a rendered `Video` to a (fake) published social post: an admin creates a `Publication` (Video × SocialAccount), generates a per-platform caption/hashtags via LLM, schedules it, and the Laravel Scheduler automatically publishes it through `FakeSocialPublisher`, idempotently, with every permanent pipeline failure visible in the admin panel.

**Architecture:** New `app/Domain/Publishing/` module mirrors the existing `app/Domain/Video/` provider pattern (`SocialPublisherInterface` + `FakeSocialPublisher`, bound in a new `PublishingServiceProvider`). `GenerateCaptionsJob` and `PublishVideoJob` follow the existing job conventions (`ShouldBeUnique`, status guards, `backoff()`). A new artisan command polled by the Laravel Scheduler dispatches due publications. A cross-cutting `NotifiesOnPermanentFailure` trait + database notifications close a gap flagged in every Phase 2–3e review: permanent job failures were only logged, never surfaced to the admin.

**Tech Stack:** Laravel 12, Filament 4, PostgreSQL (`jsonb`), Laravel Queues (Horizon), PHPUnit, Livewire testing helpers.

**Spec:** `docs/superpowers/specs/2026-09-17-phase4-publishing-design.md`

## Global Constraints

- New DB columns use `jsonb` for array/object data, matching every existing `metadata` column (`decimal`/`text`/`jsonb` conventions already in use — see `database/migrations/2026_09_11_190948_create_publications_table.php`).
- Every new job follows the existing pattern: `ShouldBeUnique` with `uniqueId()` returning the primary key as a string, a status guard as the first line of `handle()`, and a `backoff()` array — no exceptions.
- Log channels are fixed by `config/logging.php`: use `'content'` for content/script-adjacent jobs, `'video'` for the 6 video-pipeline jobs, `'publishing'` for caption/publish jobs. Do not invent new channels.
- No new Composer dependency for the calendar view — `saade/filament-fullcalendar` has no stable Filament 4 release (confirmed against Packagist during spec review); the deliverable closes via `PublicationsTable` filters/sort instead.
- `SocialAccount.access_token`/`refresh_token` stay `encrypted` casts (already in place) — never log or echo their raw values.
- Every new/changed Eloquent-touching class gets a PHPUnit `Tests\Feature\...` test in the same task that introduces it — no task is "done" without a green test run for its own change.

---

### Task 1: `publications.caption`/`hashtags` columns + model + factory

**Files:**
- Create: `database/migrations/2026_09_17_130000_add_caption_and_hashtags_to_publications_table.php`
- Modify: `app/Models/Publication.php`
- Modify: `database/factories/PublicationFactory.php`
- Test: `tests/Feature/Models/PublicationTest.php`

**Interfaces:**
- Produces: `Publication.caption` (`?string`), `Publication.hashtags` (`array<int, string>`, cast `array`) — consumed by Task 4 (`GenerateCaptionsService`/`Job`) and Task 13 (`PublicationForm`).

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->text('caption')->nullable()->after('social_account_id');
            $table->jsonb('hashtags')->default('[]')->after('caption');
        });
    }

    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->dropColumn(['caption', 'hashtags']);
        });
    }
};
```

- [ ] **Step 2: Update the `Publication` model**

In `app/Models/Publication.php`, add `'caption'` and `'hashtags'` to `$fillable`, and add `'hashtags' => 'array'` to `casts()`:

```php
protected $fillable = [
    'video_id', 'social_account_id', 'caption', 'hashtags', 'scheduled_at', 'published_at',
    'external_post_id', 'status', 'error_message', 'metadata',
];

protected function casts(): array
{
    return [
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'status' => PublicationStatus::class,
        'hashtags' => 'array',
        'metadata' => 'array',
    ];
}
```

- [ ] **Step 3: Update `PublicationFactory`**

In `database/factories/PublicationFactory.php`, add the two new fields to `definition()`:

```php
'caption' => null,
'hashtags' => [],
```
(placed right after `'social_account_id' => SocialAccount::factory(),`)

- [ ] **Step 4: Write a failing test**

```php
<?php

namespace Tests\Feature\Models;

use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_caption_and_hashtags(): void
    {
        $publication = Publication::factory()->create([
            'caption' => 'Check this out!',
            'hashtags' => ['fyp', 'viral'],
        ]);

        $fresh = $publication->fresh();
        $this->assertSame('Check this out!', $fresh->caption);
        $this->assertSame(['fyp', 'viral'], $fresh->hashtags);
    }
}
```

- [ ] **Step 5: Run migrations and the test**

Run: `php artisan migrate --env=testing` (or let `RefreshDatabase` apply it), then `php artisan test --filter=PublicationTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_17_130000_add_caption_and_hashtags_to_publications_table.php app/Models/Publication.php database/factories/PublicationFactory.php tests/Feature/Models/PublicationTest.php
git commit -m "Add caption/hashtags columns to publications"
```

---

### Task 2: `SocialPublisherInterface` + `PublishResult` + `FakeSocialPublisher` + provider registration

**Files:**
- Create: `app/Domain/Publishing/SocialPublisherInterface.php`
- Create: `app/Domain/Publishing/PublishResult.php`
- Create: `app/Domain/Publishing/Providers/FakeSocialPublisher.php`
- Create: `app/Providers/PublishingServiceProvider.php`
- Modify: `bootstrap/providers.php`

**Interfaces:**
- Produces: `SocialPublisherInterface::publish(Publication): PublishResult`, `PublishResult::$externalPostId` (`string`), `PublishResult::$metadata` (`array`), `FakeSocialPublisher::respondWith(PublishResult): static` — consumed by Task 6 (`PublishVideoJob`).

- [ ] **Step 1: Write the interface**

```php
<?php

namespace App\Domain\Publishing;

use App\Models\Publication;

interface SocialPublisherInterface
{
    public function publish(Publication $publication): PublishResult;
}
```

- [ ] **Step 2: Write the `PublishResult` DTO**

```php
<?php

namespace App\Domain\Publishing;

final class PublishResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $externalPostId,
        public readonly array $metadata = [],
    ) {}
}
```

- [ ] **Step 3: Write `FakeSocialPublisher`**

```php
<?php

namespace App\Domain\Publishing\Providers;

use App\Domain\Publishing\PublishResult;
use App\Domain\Publishing\SocialPublisherInterface;
use App\Models\Publication;
use Illuminate\Support\Str;

final class FakeSocialPublisher implements SocialPublisherInterface
{
    private ?PublishResult $result = null;

    public function respondWith(PublishResult $result): static
    {
        $this->result = $result;

        return $this;
    }

    public function publish(Publication $publication): PublishResult
    {
        return $this->result ?? new PublishResult(
            externalPostId: 'fake-'.Str::uuid(),
            metadata: ['platform' => $publication->socialAccount->platform->value],
        );
    }
}
```

- [ ] **Step 4: Write the service provider**

```php
<?php

namespace App\Providers;

use App\Domain\Publishing\Providers\FakeSocialPublisher;
use App\Domain\Publishing\SocialPublisherInterface;
use Illuminate\Support\ServiceProvider;

class PublishingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SocialPublisherInterface::class, FakeSocialPublisher::class);
    }
}
```

- [ ] **Step 5: Register the provider**

In `bootstrap/providers.php`, add the import and array entry (after `RenderServiceProvider::class,`):

```php
use App\Providers\PublishingServiceProvider;
// ...
return [
    // ...
    RenderServiceProvider::class,
    PublishingServiceProvider::class,
];
```

- [ ] **Step 6: Verify the binding resolves**

Run: `php artisan tinker --execute="dd(app(App\Domain\Publishing\SocialPublisherInterface::class)::class);"`
Expected: outputs `App\Domain\Publishing\Providers\FakeSocialPublisher`

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Publishing/SocialPublisherInterface.php app/Domain/Publishing/PublishResult.php app/Domain/Publishing/Providers/FakeSocialPublisher.php app/Providers/PublishingServiceProvider.php bootstrap/providers.php
git commit -m "Add SocialPublisherInterface and FakeSocialPublisher"
```

---

### Task 3: Database notifications + `PipelineJobFailedNotification` + `NotifiesOnPermanentFailure` trait

**Files:**
- Create: (generated) `database/migrations/*_create_notifications_table.php`
- Create: `app/Notifications/PipelineJobFailedNotification.php`
- Create: `app/Jobs/Concerns/NotifiesOnPermanentFailure.php`
- Test: `tests/Unit/Jobs/Concerns/NotifiesOnPermanentFailureTest.php`

**Interfaces:**
- Produces: `NotifiesOnPermanentFailure::notifyPermanentFailure(string $logChannel, string $message, array $context): void` — consumed by Tasks 6, 8, 9, 10 (`PublishVideoJob`, `GenerateScriptJob`, the 5 video-pipeline jobs, `GenerateScenesJob`).

- [ ] **Step 1: Generate the notifications table migration**

Run: `php artisan notifications:table`
Expected: creates `database/migrations/<timestamp>_create_notifications_table.php` with Laravel's standard `id (uuid)`, `type`, `notifiable_type`/`notifiable_id`, `data`, `read_at`, timestamps columns. `App\Models\User` already `use Notifiable` (see `app/Models/User.php:16`) — no model change needed.

- [ ] **Step 2: Write `PipelineJobFailedNotification`**

```php
<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class PipelineJobFailedNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $message,
        public readonly array $context,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
```

- [ ] **Step 3: Write the `NotifiesOnPermanentFailure` trait**

```php
<?php

namespace App\Jobs\Concerns;

use App\Models\User;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

trait NotifiesOnPermanentFailure
{
    /**
     * @param  array<string, mixed>  $context
     */
    protected function notifyPermanentFailure(string $logChannel, string $message, array $context): void
    {
        Log::channel($logChannel)->error($message, $context);

        Notification::send(User::all(), new PipelineJobFailedNotification($message, $context));
    }
}
```

- [ ] **Step 4: Write a failing test**

```php
<?php

namespace Tests\Unit\Jobs\Concerns;

use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\User;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotifiesOnPermanentFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_notifies_every_user_with_the_given_message_and_context(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $subject = new class
        {
            use NotifiesOnPermanentFailure;

            public function trigger(): void
            {
                $this->notifyPermanentFailure('video', 'Something failed permanently.', ['video_id' => 42]);
            }
        };

        $subject->trigger();

        Notification::assertSentTo(
            $user,
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->message === 'Something failed permanently.'
                && $notification->context === ['video_id' => 42]
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=NotifiesOnPermanentFailureTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/*_create_notifications_table.php app/Notifications/PipelineJobFailedNotification.php app/Jobs/Concerns/NotifiesOnPermanentFailure.php tests/Unit/Jobs/Concerns/NotifiesOnPermanentFailureTest.php
git commit -m "Add database notifications and NotifiesOnPermanentFailure trait"
```

---

### Task 4: `GenerateCaptionsService`

**Files:**
- Create: `app/Domain/Publishing/Exceptions/CaptionGenerationFailedException.php`
- Create: `app/Domain/Publishing/Services/GenerateCaptionsService.php`
- Test: `tests/Unit/Domain/Publishing/GenerateCaptionsServiceTest.php`

**Interfaces:**
- Consumes: `LlmManagerInterface::complete()` (`app/Domain/Llm/LlmManagerInterface.php`), `ResolvedLlmTarget` (`app/Domain/Llm/ResolvedLlmTarget.php`).
- Produces: `GenerateCaptionsService::generate(Publication, ResolvedLlmTarget): array{caption: string, hashtags: array<int, string>}` — consumed by Task 5 (`GenerateCaptionsJob`).

- [ ] **Step 1: Write the exception**

```php
<?php

namespace App\Domain\Publishing\Exceptions;

use RuntimeException;

final class CaptionGenerationFailedException extends RuntimeException {}
```

- [ ] **Step 2: Write the service** (mirrors `app/Domain/Content/Services/GenerateScriptService.php`)

```php
<?php

namespace App\Domain\Publishing\Services;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\ResolvedLlmTarget;
use App\Domain\Publishing\Exceptions\CaptionGenerationFailedException;
use App\Models\Publication;
use InvalidArgumentException;
use JsonException;

final class GenerateCaptionsService
{
    private const MAX_REPAIR_ATTEMPTS = 2;

    public function __construct(private readonly LlmManagerInterface $llmManager) {}

    /**
     * @return array{caption: string, hashtags: array<int, string>}
     */
    public function generate(Publication $publication, ResolvedLlmTarget $target): array
    {
        $project = $publication->video->contentProject;
        $messages = $this->buildMessages($publication);
        $lastError = 'unknown validation error';

        for ($attempt = 0; $attempt <= self::MAX_REPAIR_ATTEMPTS; $attempt++) {
            $response = $this->llmManager->complete(
                project: $project,
                purpose: 'captions',
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

        throw new CaptionGenerationFailedException($lastError);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(Publication $publication): array
    {
        $video = $publication->video;
        $platform = $publication->socialAccount->platform->value;

        $system = sprintf(
            'You are a social media copywriter. Write a caption and hashtags for a short vertical video '.
            'being posted to %s. Respond only with JSON matching the given schema — no prose outside the JSON.',
            $platform,
        );

        $user = sprintf(
            "Video title: %s\nVideo description: %s",
            $video->title,
            $video->description,
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
            'name' => 'publication_caption',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'caption' => ['type' => 'string'],
                    'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['caption', 'hashtags'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    /**
     * @return array{caption: string, hashtags: array<int, string>}
     */
    private function parse(string $content): array
    {
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new InvalidArgumentException('Response is not a JSON object.');
        }

        foreach (['caption', 'hashtags'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Missing required field [{$field}].");
            }
        }

        if (! is_string($data['caption'])) {
            throw new InvalidArgumentException('Field [caption] must be a string.');
        }

        if (! is_array($data['hashtags'])) {
            throw new InvalidArgumentException('Field [hashtags] must be an array.');
        }

        foreach ($data['hashtags'] as $tag) {
            if (! is_string($tag)) {
                throw new InvalidArgumentException('Field [hashtags] must contain only strings.');
            }
        }

        return [
            'caption' => $data['caption'],
            'hashtags' => array_values($data['hashtags']),
        ];
    }
}
```

- [ ] **Step 3: Write a failing test**

```php
<?php

namespace Tests\Unit\Domain\Publishing;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Domain\Publishing\Exceptions\CaptionGenerationFailedException;
use App\Domain\Publishing\Services\GenerateCaptionsService;
use App\Models\ContentProject;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateCaptionsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function publicationWithVideo(): Publication
    {
        $project = ContentProject::factory()->create(['settings' => []]);
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'title' => 'How to cook rice',
            'description' => 'A quick guide',
        ]);
        $account = SocialAccount::factory()->create(['content_project_id' => $project->id]);

        return Publication::factory()->create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => PublicationStatus::Draft,
        ]);
    }

    public function test_it_parses_a_valid_response(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['caption' => 'Rice made easy!', 'hashtags' => ['cooking', 'rice']])
            );
        });

        $publication = $this->publicationWithVideo()->load('video.contentProject', 'socialAccount');
        $target = app(\App\Domain\Llm\LlmManagerInterface::class)->resolve($publication->video->contentProject, 'captions');

        $service = app(GenerateCaptionsService::class);
        $result = $service->generate($publication, $target);

        $this->assertSame('Rice made easy!', $result['caption']);
        $this->assertSame(['cooking', 'rice'], $result['hashtags']);
    }

    public function test_it_throws_after_exhausting_repair_attempts_on_invalid_json(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith('not json');
        });

        $publication = $this->publicationWithVideo()->load('video.contentProject', 'socialAccount');
        $target = app(\App\Domain\Llm\LlmManagerInterface::class)->resolve($publication->video->contentProject, 'captions');

        $service = app(GenerateCaptionsService::class);

        $this->expectException(CaptionGenerationFailedException::class);
        $service->generate($publication, $target);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=GenerateCaptionsServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Publishing/Exceptions/CaptionGenerationFailedException.php app/Domain/Publishing/Services/GenerateCaptionsService.php tests/Unit/Domain/Publishing/GenerateCaptionsServiceTest.php
git commit -m "Add GenerateCaptionsService"
```

---

### Task 5: `GenerateCaptionsJob`

**Files:**
- Create: `app/Jobs/GenerateCaptionsJob.php`
- Test: `tests/Feature/Jobs/GenerateCaptionsJobTest.php`

**Interfaces:**
- Consumes: `LlmManagerInterface::resolve()` (`app/Domain/Llm/LlmManagerInterface.php`), `GenerateCaptionsService::generate()` (Task 4).
- Produces: `GenerateCaptionsJob(int $publicationId)` — consumed by Task 15 (`PublicationsTable` "Generate Captions" action).

- [ ] **Step 1: Write the job**

```php
<?php

namespace App\Jobs;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Publishing\Services\GenerateCaptionsService;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateCaptionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public int $uniqueFor = 200;

    public function __construct(public readonly int $publicationId) {}

    public function uniqueId(): string
    {
        return (string) $this->publicationId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(LlmManagerInterface $llmManager, GenerateCaptionsService $service): void
    {
        $publication = Publication::with(['video.contentProject', 'socialAccount'])->findOrFail($this->publicationId);

        if ($publication->status !== PublicationStatus::Draft || $publication->caption !== null) {
            return;
        }

        $target = $llmManager->resolve($publication->video->contentProject, 'captions');

        $data = $service->generate($publication, $target);

        $publication->update([
            'caption' => $data['caption'],
            'hashtags' => $data['hashtags'],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('publishing')->error('Caption generation failed permanently.', [
            'publication_id' => $this->publicationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

Note: `GenerateCaptionsJob` is intentionally **not** wired into `NotifiesOnPermanentFailure` — the spec's batch failure-notification fix (Task 3) covers the 6 video-pipeline jobs plus `GenerateScriptJob` and `PublishVideoJob` (8 jobs total); caption generation failing just leaves `caption` null and the "Generate Captions" button visible for a manual retry, no separate signal needed.

- [ ] **Step 2: Write a failing test**

```php
<?php

namespace Tests\Feature\Jobs;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Jobs\GenerateCaptionsJob;
use App\Models\ContentProject;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateCaptionsJobTest extends TestCase
{
    use RefreshDatabase;

    private function draftPublication(): Publication
    {
        $project = ContentProject::factory()->create(['settings' => []]);
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'title' => 'How to cook rice',
            'description' => 'A quick guide',
        ]);
        $account = SocialAccount::factory()->create(['content_project_id' => $project->id]);

        return Publication::factory()->create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => PublicationStatus::Draft,
            'caption' => null,
        ]);
    }

    public function test_it_generates_a_caption_and_hashtags(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['caption' => 'Rice made easy!', 'hashtags' => ['cooking', 'rice']])
            );
        });

        $publication = $this->draftPublication();

        app()->call([new GenerateCaptionsJob($publication->id), 'handle']);

        $fresh = $publication->fresh();
        $this->assertSame('Rice made easy!', $fresh->caption);
        $this->assertSame(['cooking', 'rice'], $fresh->hashtags);
    }

    public function test_it_is_a_no_op_when_caption_is_already_set(): void
    {
        $publication = $this->draftPublication();
        $publication->update(['caption' => 'Already there']);

        app()->call([new GenerateCaptionsJob($publication->id), 'handle']);

        $this->assertSame('Already there', $publication->fresh()->caption);
    }

    public function test_it_is_a_no_op_when_the_publication_is_not_draft(): void
    {
        $publication = $this->draftPublication();
        $publication->update(['status' => PublicationStatus::Scheduled]);

        app()->call([new GenerateCaptionsJob($publication->id), 'handle']);

        $this->assertNull($publication->fresh()->caption);
    }
}
```

- [ ] **Step 3: Run test to verify it passes**

Run: `php artisan test --filter=GenerateCaptionsJobTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Jobs/GenerateCaptionsJob.php tests/Feature/Jobs/GenerateCaptionsJobTest.php
git commit -m "Add GenerateCaptionsJob"
```

---

### Task 6: `PublishVideoJob`

**Files:**
- Create: `app/Jobs/PublishVideoJob.php`
- Test: `tests/Feature/Jobs/PublishVideoJobTest.php`

**Interfaces:**
- Consumes: `SocialPublisherInterface::publish()` (Task 2), `NotifiesOnPermanentFailure::notifyPermanentFailure()` (Task 3).
- Produces: `PublishVideoJob(int $publicationId)` — consumed by Task 7 (`DispatchDuePublicationsCommand`).

- [ ] **Step 1: Write the job**

```php
<?php

namespace App\Jobs;

use App\Domain\Publishing\SocialPublisherInterface;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public int $uniqueFor = 150;

    public function __construct(public readonly int $publicationId) {}

    public function uniqueId(): string
    {
        return (string) $this->publicationId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60, 120];
    }

    public function handle(SocialPublisherInterface $publisher): void
    {
        $publication = Publication::with('socialAccount')->findOrFail($this->publicationId);

        if ($publication->status !== PublicationStatus::Scheduled) {
            return;
        }

        $publication->update(['status' => PublicationStatus::Publishing]);

        $result = $publisher->publish($publication);

        DB::transaction(function () use ($publication, $result) {
            $publication->update([
                'status' => PublicationStatus::Published,
                'published_at' => now(),
                'external_post_id' => $result->externalPostId,
                'metadata' => array_merge($publication->metadata ?? [], $result->metadata),
            ]);
        });
    }

    public function failed(Throwable $exception): void
    {
        Publication::whereKey($this->publicationId)->update(['status' => PublicationStatus::Failed]);

        $this->notifyPermanentFailure('publishing', 'Publication failed permanently.', [
            'publication_id' => $this->publicationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

- [ ] **Step 2: Write a failing test**

```php
<?php

namespace Tests\Feature\Jobs;

use App\Domain\Publishing\Providers\FakeSocialPublisher;
use App\Domain\Publishing\PublishResult;
use App\Domain\Publishing\SocialPublisherInterface;
use App\Jobs\PublishVideoJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PublishVideoJobTest extends TestCase
{
    use RefreshDatabase;

    private function bindFakePublisher(?PublishResult $result = null): void
    {
        $this->app->bind(SocialPublisherInterface::class, function () use ($result) {
            $fake = new FakeSocialPublisher;

            return $result !== null ? $fake->respondWith($result) : $fake;
        });
    }

    public function test_it_publishes_and_updates_the_publication(): void
    {
        $this->bindFakePublisher(new PublishResult(externalPostId: 'ext-1', metadata: ['url' => 'https://example.com/1']));

        $publication = Publication::factory()->create(['status' => PublicationStatus::Scheduled]);

        app()->call([new PublishVideoJob($publication->id), 'handle']);

        $fresh = $publication->fresh();
        $this->assertSame(PublicationStatus::Published, $fresh->status);
        $this->assertSame('ext-1', $fresh->external_post_id);
        $this->assertSame('https://example.com/1', $fresh->metadata['url']);
        $this->assertNotNull($fresh->published_at);
    }

    public function test_it_is_a_no_op_when_the_publication_is_not_scheduled(): void
    {
        $this->bindFakePublisher();

        $publication = Publication::factory()->create(['status' => PublicationStatus::Draft]);

        app()->call([new PublishVideoJob($publication->id), 'handle']);

        $this->assertSame(PublicationStatus::Draft, $publication->fresh()->status);
        $this->assertNull($publication->fresh()->external_post_id);
    }

    public function test_calling_handle_twice_does_not_publish_twice(): void
    {
        $this->bindFakePublisher(new PublishResult(externalPostId: 'ext-1'));

        $publication = Publication::factory()->create(['status' => PublicationStatus::Scheduled]);

        app()->call([new PublishVideoJob($publication->id), 'handle']);
        app()->call([new PublishVideoJob($publication->id), 'handle']);

        $this->assertSame('ext-1', $publication->fresh()->external_post_id);
        $this->assertSame(PublicationStatus::Published, $publication->fresh()->status);
    }

    public function test_failed_marks_the_publication_failed_and_sends_a_notification(): void
    {
        Notification::fake();
        User::factory()->create();

        $publication = Publication::factory()->create(['status' => PublicationStatus::Publishing]);

        $job = new PublishVideoJob($publication->id);
        $job->failed(new \RuntimeException('API unavailable'));

        $this->assertSame(PublicationStatus::Failed, $publication->fresh()->status);

        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->context['publication_id'] === $publication->id
        );
    }
}
```

- [ ] **Step 3: Run test to verify it passes**

Run: `php artisan test --filter=PublishVideoJobTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Jobs/PublishVideoJob.php tests/Feature/Jobs/PublishVideoJobTest.php
git commit -m "Add PublishVideoJob"
```

---

### Task 7: `publications:dispatch-due` command + scheduler registration

**Files:**
- Create: `app/Console/Commands/DispatchDuePublicationsCommand.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Console/DispatchDuePublicationsCommandTest.php`

**Interfaces:**
- Consumes: `PublishVideoJob` (Task 6).

- [ ] **Step 1: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Jobs\PublishVideoJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use Illuminate\Console\Command;

class DispatchDuePublicationsCommand extends Command
{
    protected $signature = 'publications:dispatch-due';

    protected $description = 'Dispatch PublishVideoJob for every publication whose scheduled_at is due.';

    public function handle(): int
    {
        $due = Publication::where('status', PublicationStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $publication) {
            PublishVideoJob::dispatch($publication->id);
        }

        $this->info("Dispatched {$due->count()} publication(s).");

        return self::SUCCESS;
    }
}
```

`app/Console/Commands/` is auto-discovered by Laravel's default bootstrap (no manual registration needed, same as any other command in this directory).

- [ ] **Step 2: Register the scheduler entry**

In `bootstrap/app.php`, add the `withSchedule` import and call:

```php
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('publications:dispatch-due')
            ->everyMinute()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

- [ ] **Step 3: Write a failing test**

```php
<?php

namespace Tests\Feature\Console;

use App\Jobs\PublishVideoJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchDuePublicationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_publish_video_job_only_for_due_scheduled_publications(): void
    {
        Queue::fake();

        $due = Publication::factory()->create([
            'status' => PublicationStatus::Scheduled,
            'scheduled_at' => now()->subMinute(),
        ]);

        Publication::factory()->create([
            'status' => PublicationStatus::Scheduled,
            'scheduled_at' => now()->addHour(),
        ]);

        Publication::factory()->create([
            'status' => PublicationStatus::Draft,
            'scheduled_at' => null,
        ]);

        Artisan::call('publications:dispatch-due');

        Queue::assertPushed(PublishVideoJob::class, 1);
        Queue::assertPushed(PublishVideoJob::class, fn (PublishVideoJob $job): bool => $job->publicationId === $due->id);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=DispatchDuePublicationsCommandTest`
Expected: PASS

- [ ] **Step 5: Verify the schedule is registered**

Run: `php artisan schedule:list`
Expected: shows `publications:dispatch-due` running every minute

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/DispatchDuePublicationsCommand.php bootstrap/app.php tests/Feature/Console/DispatchDuePublicationsCommandTest.php
git commit -m "Add publications:dispatch-due command and scheduler registration"
```

---

### Task 8: Wire `NotifiesOnPermanentFailure` into `GenerateScriptJob`

**Files:**
- Modify: `app/Jobs/GenerateScriptJob.php`
- Modify: `tests/Feature/Jobs/GenerateScriptJobTest.php`

**Interfaces:**
- Consumes: `NotifiesOnPermanentFailure::notifyPermanentFailure()` (Task 3).

- [ ] **Step 1: Edit `GenerateScriptJob`**

Replace:
```php
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateScriptJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
```
with:
```php
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class GenerateScriptJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;
```
(insert the `NotifiesOnPermanentFailure` import in its alphabetical position among the existing `use App\...` lines)

Replace the tail of `failed()`:
```php
        if ($idea->status === ContentIdeaStatus::Processing) {
            $idea->update(['status' => ContentIdeaStatus::Approved]);
        }

        Log::channel('content')->error('Script generation failed permanently.', [
            'content_idea_id' => $idea->id,
            'error' => $exception->getMessage(),
        ]);
    }
```
with:
```php
        if ($idea->status === ContentIdeaStatus::Processing) {
            $idea->update(['status' => ContentIdeaStatus::Approved]);
        }

        $this->notifyPermanentFailure('content', 'Script generation failed permanently.', [
            'content_idea_id' => $idea->id,
            'error' => $exception->getMessage(),
        ]);
    }
```

- [ ] **Step 2: Update the existing failed() test**

In `tests/Feature/Jobs/GenerateScriptJobTest.php`, add imports:
```php
use App\Models\User;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Support\Facades\Notification;
```

Replace `test_failed_marks_the_script_failed_and_reverts_the_idea_to_approved`:
```php
    public function test_failed_marks_the_script_failed_and_reverts_the_idea_to_approved(): void
    {
        Notification::fake();
        User::factory()->create();

        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::Processing]);
        $script = Script::factory()->create([
            'content_idea_id' => $idea->id,
            'status' => ScriptStatus::Processing,
            'content' => null,
        ]);

        $job = new GenerateScriptJob($idea->id);
        $job->failed(new \RuntimeException('LLM unavailable'));

        $this->assertSame(ScriptStatus::Failed, $script->fresh()->status);
        $this->assertSame('LLM unavailable', $script->fresh()->metadata['error']);
        $this->assertSame(ContentIdeaStatus::Approved, $idea->fresh()->status);

        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->context['content_idea_id'] === $idea->id
        );
    }
```

- [ ] **Step 3: Run test to verify it passes**

Run: `php artisan test --filter=GenerateScriptJobTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Jobs/GenerateScriptJob.php tests/Feature/Jobs/GenerateScriptJobTest.php
git commit -m "Notify admins on permanent GenerateScriptJob failure"
```

---

### Task 9: Wire `NotifiesOnPermanentFailure` into the 5 video-pipeline jobs

**Files:**
- Modify: `app/Jobs/CollectVideoAssetsJob.php`, `app/Jobs/GenerateSubtitlesJob.php`, `app/Jobs/GenerateVoiceoverJob.php`, `app/Jobs/QualityCheckVideoJob.php`, `app/Jobs/RenderVideoJob.php`
- Modify: `tests/Feature/Jobs/CollectVideoAssetsJobTest.php`, `tests/Feature/Jobs/GenerateSubtitlesJobTest.php`, `tests/Feature/Jobs/GenerateVoiceoverJobTest.php`, `tests/Feature/Jobs/QualityCheckVideoJobTest.php`, `tests/Feature/Jobs/RenderVideoJobTest.php`

**Interfaces:**
- Consumes: `NotifiesOnPermanentFailure::notifyPermanentFailure()` (Task 3).

Each of these 5 jobs gets the identical mechanical change: add the `NotifiesOnPermanentFailure` trait, drop the now-unused `Log` facade import, set `Video.status = VideoStatus::Failed` in `failed()`, and replace the direct `Log::channel('video')->error(...)` call with `$this->notifyPermanentFailure('video', ...)`.

- [ ] **Step 1: Edit `CollectVideoAssetsJob`**

Replace:
```php
use App\Domain\Video\Services\CollectVideoAssetsService;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectVideoAssetsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
```
with:
```php
use App\Domain\Video\Services\CollectVideoAssetsService;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

class CollectVideoAssetsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;
```

Replace `failed()`:
```php
    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update(['status' => VideoStatus::Failed]);

        $this->notifyPermanentFailure('video', 'Asset collection failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
```

- [ ] **Step 2: Add a failed() test to `CollectVideoAssetsJobTest`**

Add imports:
```php
use App\Models\User;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Support\Facades\Notification;
```

Add test method:
```php
    public function test_failed_marks_the_video_failed_and_sends_a_notification(): void
    {
        Notification::fake();
        User::factory()->create();

        $video = Video::factory()->create(['status' => VideoStatus::VoiceGenerated]);

        $job = new CollectVideoAssetsJob($video->id);
        $job->failed(new \RuntimeException('boom'));

        $this->assertSame(VideoStatus::Failed, $video->fresh()->status);

        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->context['video_id'] === $video->id
        );
    }
```

- [ ] **Step 3: Edit `GenerateSubtitlesJob`**

Replace:
```php
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
```
with:
```php
use App\Domain\Video\Services\GenerateSubtitlesService;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
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
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateSubtitlesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;
```

Replace `failed()`:
```php
    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update(['status' => VideoStatus::Failed]);

        $this->notifyPermanentFailure('video', 'Subtitle generation failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
```

- [ ] **Step 4: Add a failed() test to `GenerateSubtitlesJobTest`**

Add the same 3 imports as Step 2, then:
```php
    public function test_failed_marks_the_video_failed_and_sends_a_notification(): void
    {
        Notification::fake();
        User::factory()->create();

        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady]);

        $job = new GenerateSubtitlesJob($video->id);
        $job->failed(new \RuntimeException('boom'));

        $this->assertSame(VideoStatus::Failed, $video->fresh()->status);

        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->context['video_id'] === $video->id
        );
    }
```

- [ ] **Step 5: Edit `GenerateVoiceoverJob`**

Replace:
```php
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
```
with:
```php
use App\Domain\Video\Services\GenerateVoiceoverService;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
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
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateVoiceoverJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;
```

Replace `failed()`:
```php
    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update(['status' => VideoStatus::Failed]);

        $this->notifyPermanentFailure('video', 'Voiceover generation failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
```

- [ ] **Step 6: Add a failed() test to `GenerateVoiceoverJobTest`**

Add the same 3 imports as Step 2, then:
```php
    public function test_failed_marks_the_video_failed_and_sends_a_notification(): void
    {
        Notification::fake();
        User::factory()->create();

        $video = Video::factory()->create(['status' => VideoStatus::ScriptGenerated]);

        $job = new GenerateVoiceoverJob($video->id);
        $job->failed(new \RuntimeException('boom'));

        $this->assertSame(VideoStatus::Failed, $video->fresh()->status);

        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->context['video_id'] === $video->id
        );
    }
```

- [ ] **Step 7: Edit `QualityCheckVideoJob`**

Replace:
```php
use App\Domain\Video\VideoQualityCheckerInterface;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class QualityCheckVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
```
with:
```php
use App\Domain\Video\VideoQualityCheckerInterface;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class QualityCheckVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;
```

Replace `failed()`:
```php
    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update(['status' => VideoStatus::Failed]);

        $this->notifyPermanentFailure('video', 'Quality check failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
```

- [ ] **Step 8: Add a failed() test to `QualityCheckVideoJobTest`**

Add the same 3 imports as Step 2, then:
```php
    public function test_failed_marks_the_video_failed_and_sends_a_notification(): void
    {
        Notification::fake();
        User::factory()->create();

        $video = Video::factory()->create(['status' => VideoStatus::Rendered]);

        $job = new QualityCheckVideoJob($video->id);
        $job->failed(new \RuntimeException('boom'));

        $this->assertSame(VideoStatus::Failed, $video->fresh()->status);

        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->context['video_id'] === $video->id
        );
    }
```

- [ ] **Step 9: Edit `RenderVideoJob`**

Replace:
```php
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RenderVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
```
with:
```php
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

class RenderVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;
```
(insert the `NotifiesOnPermanentFailure` import in its alphabetical position among the existing `use App\...` lines)

Replace `failed()`:
```php
    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update(['status' => VideoStatus::Failed]);

        $this->notifyPermanentFailure('video', 'Video rendering failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
```

- [ ] **Step 10: Add a failed() test to `RenderVideoJobTest`**

Add the same 3 imports as Step 2, then (reusing the existing `videoReadyForRendering()` private helper already in this file):
```php
    public function test_failed_marks_the_video_failed_and_sends_a_notification(): void
    {
        Notification::fake();
        User::factory()->create();

        $video = $this->videoReadyForRendering();

        $job = new RenderVideoJob($video->id);
        $job->failed(new \RuntimeException('ffmpeg crashed'));

        $this->assertSame(VideoStatus::Failed, $video->fresh()->status);

        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->context['video_id'] === $video->id
        );
    }
```

- [ ] **Step 11: Run all 5 test files**

Run: `php artisan test --filter="CollectVideoAssetsJobTest|GenerateSubtitlesJobTest|GenerateVoiceoverJobTest|QualityCheckVideoJobTest|RenderVideoJobTest"`
Expected: PASS

- [ ] **Step 12: Commit**

```bash
git add app/Jobs/CollectVideoAssetsJob.php app/Jobs/GenerateSubtitlesJob.php app/Jobs/GenerateVoiceoverJob.php app/Jobs/QualityCheckVideoJob.php app/Jobs/RenderVideoJob.php tests/Feature/Jobs/CollectVideoAssetsJobTest.php tests/Feature/Jobs/GenerateSubtitlesJobTest.php tests/Feature/Jobs/GenerateVoiceoverJobTest.php tests/Feature/Jobs/QualityCheckVideoJobTest.php tests/Feature/Jobs/RenderVideoJobTest.php
git commit -m "Notify admins and mark video failed on permanent pipeline job failure"
```

---

### Task 10: Wire `NotifiesOnPermanentFailure` into `GenerateScenesJob`

`GenerateScenesJob` is a special case: it operates on a `Script`, and the `Video` row is only created (via `firstOrCreate`) *after* scene generation succeeds (see `app/Jobs/GenerateScenesJob.php:59-69`). On permanent failure the `Video` may not exist yet — the job must look it up defensively rather than assume it exists.

**Files:**
- Modify: `app/Jobs/GenerateScenesJob.php`
- Modify: `tests/Feature/Jobs/GenerateScenesJobTest.php`

**Interfaces:**
- Consumes: `NotifiesOnPermanentFailure::notifyPermanentFailure()` (Task 3).

- [ ] **Step 1: Edit `GenerateScenesJob`**

Replace:
```php
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateScenesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
```
with:
```php
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateScenesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;
```
(insert the `NotifiesOnPermanentFailure` import in its alphabetical position among the existing `use App\...` lines)

Replace `failed()`:
```php
    public function failed(Throwable $exception): void
    {
        $video = Video::where('script_id', $this->scriptId)->first();

        if ($video !== null) {
            $video->update(['status' => VideoStatus::Failed]);
        }

        $this->notifyPermanentFailure('video', 'Scene generation failed permanently.', [
            'script_id' => $this->scriptId,
            'error' => $exception->getMessage(),
        ]);
    }
```

- [ ] **Step 2: Add two failed() tests to `GenerateScenesJobTest`**

Add imports:
```php
use App\Models\User;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Support\Facades\Notification;
```

Add test methods (reusing the existing `scriptWithCompletedStatus()` private helper already in this file):
```php
    public function test_failed_notifies_admins_when_no_video_exists_yet(): void
    {
        Notification::fake();
        User::factory()->create();

        $script = $this->scriptWithCompletedStatus();

        $job = new GenerateScenesJob($script->id);
        $job->failed(new \RuntimeException('boom'));

        $this->assertDatabaseCount('videos', 0);

        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->context['script_id'] === $script->id
        );
    }

    public function test_failed_marks_an_existing_video_failed(): void
    {
        Notification::fake();
        User::factory()->create();

        $script = $this->scriptWithCompletedStatus();
        $video = Video::factory()->create([
            'script_id' => $script->id,
            'content_project_id' => $script->contentIdea->content_project_id,
            'content_idea_id' => $script->content_idea_id,
            'status' => VideoStatus::ScriptGenerated,
        ]);

        $job = new GenerateScenesJob($script->id);
        $job->failed(new \RuntimeException('boom'));

        $this->assertSame(VideoStatus::Failed, $video->fresh()->status);
    }
```

- [ ] **Step 3: Run test to verify it passes**

Run: `php artisan test --filter=GenerateScenesJobTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Jobs/GenerateScenesJob.php tests/Feature/Jobs/GenerateScenesJobTest.php
git commit -m "Notify admins on permanent GenerateScenesJob failure"
```

---

### Task 11: `->databaseNotifications()` in the admin panel

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`

- [ ] **Step 1: Add the call**

In `app/Providers/Filament/AdminPanelProvider.php`, add `->databaseNotifications()` to the panel chain, right after `->discoverWidgets(...)->widgets([...])`:

```php
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->databaseNotifications()
            ->middleware([
```

- [ ] **Step 2: Verify manually**

Run: `php artisan serve` (or use the existing dev stack), log into `/admin`, confirm a notification bell icon appears in the topbar with no errors. Stop the server afterward.

- [ ] **Step 3: Commit**

```bash
git add app/Providers/Filament/AdminPanelProvider.php
git commit -m "Enable database notifications in the admin panel"
```

---

### Task 12: `SocialAccountForm` — password-masked token fields

`SocialAccountForm` currently has no fields for `access_token`/`refresh_token` at all, even though `access_token` is a required `NOT NULL` column (`database/migrations/2026_09_11_190947_create_social_accounts_table.php:21`) — creating a `SocialAccount` through the admin panel currently fails with a DB constraint violation. This task fixes that.

**Files:**
- Modify: `app/Filament/Resources/SocialAccounts/Schemas/SocialAccountForm.php`
- Test: `tests/Feature/Filament/SocialAccountFormTest.php`

- [ ] **Step 1: Edit the form**

Replace the full file:
```php
<?php

namespace App\Filament\Resources\SocialAccounts\Schemas;

use App\Models\Enums\SocialPlatform;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SocialAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('content_project_id')
                    ->relationship('contentProject', 'name')
                    ->required(),
                Select::make('platform')
                    ->options(SocialPlatform::class)
                    ->required(),
                TextInput::make('external_account_id')
                    ->required(),
                TextInput::make('username')
                    ->required(),
                TextInput::make('access_token')
                    ->password()
                    ->revealable()
                    ->required(),
                TextInput::make('refresh_token')
                    ->password()
                    ->revealable(),
                DateTimePicker::make('token_expires_at'),
                TextInput::make('metadata')
                    ->required()
                    ->default('{}')
                    ->disabled(),
                TextInput::make('status')
                    ->required(),
            ]);
    }
}
```

- [ ] **Step 2: Write a failing test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\SocialAccounts\Pages\CreateSocialAccount;
use App\Models\ContentProject;
use App\Models\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SocialAccountFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_social_account_saves_the_encrypted_tokens(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create();

        Livewire::test(CreateSocialAccount::class)
            ->fillForm([
                'content_project_id' => $project->id,
                'platform' => SocialPlatform::TikTok->value,
                'external_account_id' => 'ext-123',
                'username' => 'my_account',
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $account = SocialAccount::sole();
        $this->assertSame('secret-access-token', $account->access_token);
        $this->assertSame('secret-refresh-token', $account->refresh_token);
    }
}
```

- [ ] **Step 3: Run test to verify it passes**

Run: `php artisan test --filter=SocialAccountFormTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Resources/SocialAccounts/Schemas/SocialAccountForm.php tests/Feature/Filament/SocialAccountFormTest.php
git commit -m "Add access_token/refresh_token fields to SocialAccountForm"
```

---

### Task 13: `PublicationForm` — reactive account select, caption/hashtags, derived status display

**Files:**
- Modify: `app/Filament/Resources/Publications/Schemas/PublicationForm.php`

**Interfaces:**
- Consumes: `Publication.caption`/`hashtags` (Task 1).

- [ ] **Step 1: Replace the form**

```php
<?php

namespace App\Filament\Resources\Publications\Schemas;

use App\Models\Enums\PublicationStatus;
use App\Models\Video;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class PublicationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('video_id')
                    ->relationship('video', 'title')
                    ->searchable()
                    ->live()
                    ->required(),
                Select::make('social_account_id')
                    ->relationship(
                        name: 'socialAccount',
                        titleAttribute: 'username',
                        modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->when(
                            $get('video_id'),
                            fn (Builder $q, $videoId) => $q->where('content_project_id', Video::find($videoId)?->content_project_id)
                        ),
                    )
                    ->searchable()
                    ->required(),
                DateTimePicker::make('scheduled_at')
                    ->native(false)
                    ->helperText('Заповніть майбутньою датою — після збереження запис автоматично перейде у статус Scheduled.'),
                Textarea::make('caption')
                    ->columnSpanFull(),
                TagsInput::make('hashtags')
                    ->separator(',')
                    // TagsInput installs its own dehydrateStateUsing that joins the array into a
                    // comma-separated string whenever a separator is set — this override keeps
                    // the state a plain array so it round-trips through the jsonb column.
                    ->dehydrateStateUsing(fn ($state) => $state ?? []),
                Select::make('status')
                    ->options(PublicationStatus::class)
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Статус веде pipeline автоматично: Draft → Scheduled → Publishing → Published/Failed.'),
                TextInput::make('external_post_id')
                    ->disabled()
                    ->dehydrated(false),
                DateTimePicker::make('published_at')
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }
}
```

- [ ] **Step 2: Verify existing Filament resource tests still pass**

Run: `php artisan test --filter=FilamentResourcesTest`
Expected: PASS (this suite smoke-tests every resource's pages render — Task 14 adds behavior-specific coverage for the new save logic)

- [ ] **Step 3: Commit**

```bash
git add app/Filament/Resources/Publications/Schemas/PublicationForm.php
git commit -m "Add caption/hashtags fields and account scoping to PublicationForm"
```

---

### Task 14: `Draft → Scheduled` derivation on save

**Files:**
- Create: `app/Filament/Resources/Publications/Concerns/AppliesScheduledStatus.php`
- Modify: `app/Filament/Resources/Publications/Pages/CreatePublication.php`
- Modify: `app/Filament/Resources/Publications/Pages/EditPublication.php`
- Test: `tests/Feature/Filament/PublicationScheduleFormTest.php`

**Interfaces:**
- Produces: `AppliesScheduledStatus::applyScheduledStatus(array $data, ?PublicationStatus $currentStatus): array` — shared by `CreatePublication` and `EditPublication`.

- [ ] **Step 1: Write the shared trait**

```php
<?php

namespace App\Filament\Resources\Publications\Concerns;

use App\Models\Enums\PublicationStatus;
use Illuminate\Support\Carbon;

trait AppliesScheduledStatus
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyScheduledStatus(array $data, ?PublicationStatus $currentStatus): array
    {
        // Once a publication has moved past Draft/Scheduled (Publishing, Published,
        // Failed), the pipeline owns its status — editing unrelated fields must not
        // silently reset it back to Draft/Scheduled.
        if ($currentStatus !== null && ! in_array($currentStatus, [PublicationStatus::Draft, PublicationStatus::Scheduled], true)) {
            return $data;
        }

        $data['status'] = (! empty($data['scheduled_at']) && Carbon::parse($data['scheduled_at'])->isFuture())
            ? PublicationStatus::Scheduled
            : PublicationStatus::Draft;

        return $data;
    }
}
```

- [ ] **Step 2: Wire it into `CreatePublication`**

```php
<?php

namespace App\Filament\Resources\Publications\Pages;

use App\Filament\Resources\Publications\Concerns\AppliesScheduledStatus;
use App\Filament\Resources\Publications\PublicationResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePublication extends CreateRecord
{
    use AppliesScheduledStatus;

    protected static string $resource = PublicationResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->applyScheduledStatus($data, currentStatus: null);
    }
}
```

- [ ] **Step 3: Wire it into `EditPublication`**

```php
<?php

namespace App\Filament\Resources\Publications\Pages;

use App\Filament\Resources\Publications\Concerns\AppliesScheduledStatus;
use App\Filament\Resources\Publications\PublicationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPublication extends EditRecord
{
    use AppliesScheduledStatus;

    protected static string $resource = PublicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->applyScheduledStatus($data, currentStatus: $this->record->status);
    }
}
```

- [ ] **Step 4: Write a failing test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Publications\Pages\CreatePublication;
use App\Filament\Resources\Publications\Pages\EditPublication;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PublicationScheduleFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_with_a_future_scheduled_at_sets_status_scheduled(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create();
        $account = SocialAccount::factory()->create(['content_project_id' => $video->content_project_id]);

        Livewire::test(CreatePublication::class)
            ->fillForm([
                'video_id' => $video->id,
                'social_account_id' => $account->id,
                'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $publication = Publication::sole();
        $this->assertSame(PublicationStatus::Scheduled, $publication->status);
    }

    public function test_creating_without_scheduled_at_sets_status_draft(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create();
        $account = SocialAccount::factory()->create(['content_project_id' => $video->content_project_id]);

        Livewire::test(CreatePublication::class)
            ->fillForm([
                'video_id' => $video->id,
                'social_account_id' => $account->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $publication = Publication::sole();
        $this->assertSame(PublicationStatus::Draft, $publication->status);
    }

    public function test_editing_an_already_published_publication_does_not_reset_its_status(): void
    {
        $this->actingAs(User::factory()->create());

        $publication = Publication::factory()->create([
            'status' => PublicationStatus::Published,
            'scheduled_at' => now()->subDay(),
            'published_at' => now(),
            'external_post_id' => 'ext-1',
        ]);

        Livewire::test(EditPublication::class, ['record' => $publication->getRouteKey()])
            ->fillForm(['caption' => 'Updated caption'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(PublicationStatus::Published, $publication->fresh()->status);
        $this->assertSame('Updated caption', $publication->fresh()->caption);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=PublicationScheduleFormTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/Publications/Concerns/AppliesScheduledStatus.php app/Filament/Resources/Publications/Pages/CreatePublication.php app/Filament/Resources/Publications/Pages/EditPublication.php tests/Feature/Filament/PublicationScheduleFormTest.php
git commit -m "Derive Publication.status from scheduled_at on save"
```

---

### Task 15: `PublicationsTable` — columns, filters, "Generate Captions" action

**Files:**
- Modify: `app/Filament/Resources/Publications/Tables/PublicationsTable.php`
- Test: `tests/Feature/Filament/PublicationGenerateCaptionsActionTest.php`
- Test: `tests/Feature/Filament/PublicationsTableFiltersTest.php`

**Interfaces:**
- Consumes: `GenerateCaptionsJob` (Task 5).

- [ ] **Step 1: Replace the table**

```php
<?php

namespace App\Filament\Resources\Publications\Tables;

use App\Jobs\GenerateCaptionsJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PublicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_at')
            ->columns([
                TextColumn::make('video.title')
                    ->searchable(),
                TextColumn::make('socialAccount.username')
                    ->label('Social account')
                    ->searchable(),
                TextColumn::make('socialAccount.platform')
                    ->badge(),
                TextColumn::make('scheduled_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('external_post_id')
                    ->searchable(),
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
                SelectFilter::make('status')
                    ->options(PublicationStatus::class),
                Filter::make('scheduled_at')
                    ->schema([
                        DatePicker::make('scheduled_from'),
                        DatePicker::make('scheduled_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['scheduled_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('scheduled_at', '>=', $date))
                            ->when($data['scheduled_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('scheduled_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                Action::make('generateCaptions')
                    ->label('Generate Captions')
                    ->visible(fn (Publication $record): bool => $record->status === PublicationStatus::Draft
                        && $record->caption === null)
                    ->requiresConfirmation()
                    ->action(function (Publication $record): void {
                        GenerateCaptionsJob::dispatch($record->id);

                        Notification::make()->title('Caption generation queued')->success()->send();
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

- [ ] **Step 2: Write the action test** (mirrors `tests/Feature/Filament/VideoVoiceoverActionsTest.php`)

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Publications\Pages\ListPublications;
use App\Jobs\GenerateCaptionsJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class PublicationGenerateCaptionsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_captions_action_dispatches_the_job_when_draft_and_no_caption(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $publication = Publication::factory()->create(['status' => PublicationStatus::Draft, 'caption' => null]);

        Livewire::test(ListPublications::class)
            ->callTableAction('generateCaptions', $publication)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(GenerateCaptionsJob::class, fn (GenerateCaptionsJob $job): bool => $job->publicationId === $publication->id);
    }

    public function test_generate_captions_action_is_not_visible_once_a_caption_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $publication = Publication::factory()->create(['status' => PublicationStatus::Draft, 'caption' => 'Already there']);

        Livewire::test(ListPublications::class)
            ->assertTableActionHidden('generateCaptions', $publication);
    }

    public function test_generate_captions_action_is_not_visible_once_scheduled(): void
    {
        $this->actingAs(User::factory()->create());

        $publication = Publication::factory()->create(['status' => PublicationStatus::Scheduled, 'caption' => null]);

        Livewire::test(ListPublications::class)
            ->assertTableActionHidden('generateCaptions', $publication);
    }
}
```

- [ ] **Step 3: Write the filters test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Publications\Pages\ListPublications;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PublicationsTableFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_filter_narrows_the_table_to_matching_publications(): void
    {
        $this->actingAs(User::factory()->create());

        $scheduled = Publication::factory()->create(['status' => PublicationStatus::Scheduled]);
        Publication::factory()->create(['status' => PublicationStatus::Draft]);

        Livewire::test(ListPublications::class)
            ->filterTable('status', PublicationStatus::Scheduled->value)
            ->assertCanSeeTableRecords([$scheduled])
            ->assertCountTableRecords(1);
    }

    public function test_scheduled_at_filter_narrows_the_table_to_the_given_range(): void
    {
        $this->actingAs(User::factory()->create());

        $inRange = Publication::factory()->create(['scheduled_at' => now()->addDays(2)]);
        Publication::factory()->create(['scheduled_at' => now()->addDays(10)]);

        Livewire::test(ListPublications::class)
            ->filterTable('scheduled_at', [
                'scheduled_from' => now()->format('Y-m-d'),
                'scheduled_until' => now()->addDays(5)->format('Y-m-d'),
            ])
            ->assertCanSeeTableRecords([$inRange])
            ->assertCountTableRecords(1);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="PublicationGenerateCaptionsActionTest|PublicationsTableFiltersTest"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/Publications/Tables/PublicationsTable.php tests/Feature/Filament/PublicationGenerateCaptionsActionTest.php tests/Feature/Filament/PublicationsTableFiltersTest.php
git commit -m "Add PublicationsTable filters and Generate Captions action"
```

---

### Task 16: Final verification (DoD)

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: all tests pass, including every test added in Tasks 1–15

- [ ] **Step 2: Run Pint**

Run: `./vendor/bin/pint`
Expected: no style violations (or auto-fixes applied; re-run `php artisan test` if it reformatted anything)

- [ ] **Step 3: Verify routes**

Run: `php artisan route:list`
Expected: `admin/social-accounts` and `admin/publications` resource routes present, no errors

- [ ] **Step 4: Verify migrations and seeders**

Run: `php artisan migrate:fresh --seed`
Expected: completes without errors, including the new `publications.caption`/`hashtags` columns and the `notifications` table

- [ ] **Step 5: Manually exercise the DoD scenarios (pp. 11–16, TechnicalTask.md §24)**

Using `php artisan tinker` or the admin panel at `/admin`:
1. Create a `Publication` for an existing `Video`/`SocialAccount` — confirms DoD 11.
2. Set `scheduled_at` to a future timestamp and save — confirm `status` becomes `Scheduled` — DoD 12.
3. Run `php artisan publications:dispatch-due` after temporarily setting `scheduled_at` to the past (or wait for the real minute-tick in a running `schedule:work`) — confirm `PublishVideoJob` is queued — DoD 13.
4. Let the job run (`php artisan queue:work --once` or Horizon) — confirm `status` becomes `Published`, `external_post_id` is set — DoD 14.
5. Re-run `publications:dispatch-due` on the same (now `Published`) record — confirm no duplicate `PublishVideoJob` side effects (guard returns early) — DoD 15.
6. Force a permanent failure (e.g. bind a `SocialPublisherInterface` fake that throws, exhaust retries) — confirm a database notification appears in the admin topbar bell and `Publication.status` becomes `Failed` — DoD 16.

- [ ] **Step 6: Update `ROADMAP.md`**

Mark all Phase 4 deliverables `[x]`, fill in the DoD paragraph and Spec/Plan links (mirroring the style of the Phase 3 entries), following the existing roadmap conventions exactly.

- [ ] **Step 7: Commit**

```bash
git add ROADMAP.md
git commit -m "Mark Phase 4 (Publishing) complete in roadmap"
```
