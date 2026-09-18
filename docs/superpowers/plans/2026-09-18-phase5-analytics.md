# Phase 5 — Analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collect per-publication engagement metrics on a schedule and surface pipeline health, engagement totals, and LLM cost/token breakdown in Filament.

**Architecture:** Extend `SocialPublisherInterface` with `fetchMetrics()`, poll it hourly via a new `CollectVideoMetricsJob`/`metrics:collect` command (same shape as the existing `PublishVideoJob`/`publications:dispatch-due` pair), store results as `VideoMetric` rows (an append-only time series), then read that data back through three auto-discovered Filament widgets on the Dashboard and one aggregation report Page for `LlmUsageLog`.

**Tech Stack:** Laravel 12, PHP 8.4+, Filament 4, PostgreSQL (this plan uses Postgres-only SQL: `DISTINCT ON`, `||` string concatenation), PHPUnit + `RefreshDatabase`, Livewire testing helpers.

**Spec:** `docs/superpowers/specs/2026-09-18-phase5-analytics-design.md`

## Global Constraints

- Target stack is PHP 8.4+/Laravel 12+/PostgreSQL only — raw SQL in this plan (`DISTINCT ON`, `||`) is Postgres-specific and that is intentional, matching the rest of the project.
- No comments in code unless they explain a genuinely non-obvious WHY (see existing files like `app/Jobs/PublishVideoJob.php` for the house style).
- Every new `ShouldQueue` job wires the existing `App\Jobs\Concerns\NotifiesOnPermanentFailure` trait in its `failed()` method, following the pattern of the 9 pipeline jobs from Phase 4.
- `Filament\Widgets\Widget` subclasses placed under `app/Filament/Widgets/` and `Filament\Pages\Page` subclasses placed under `app/Filament/Pages/` are auto-discovered by `AdminPanelProvider` (`discoverWidgets`/`discoverPages` are already configured) — **no manual registration in `AdminPanelProvider.php` is needed or wanted** for this plan.
- Run `php artisan test`, `./vendor/bin/pint`, `php artisan route:list`, `php artisan migrate:fresh --seed` clean at the end (Task 10), matching every prior phase's DoD verification.

---

### Task 1: `SocialPublisherInterface::fetchMetrics()` + `VideoMetricsResult` DTO + `FakeSocialPublisher`

**Files:**
- Create: `app/Domain/Publishing/VideoMetricsResult.php`
- Modify: `app/Domain/Publishing/SocialPublisherInterface.php`
- Modify: `app/Domain/Publishing/Providers/FakeSocialPublisher.php`
- Modify: `tests/Feature/Jobs/PublishVideoJobTest.php:61-73` (its inline anonymous `SocialPublisherInterface` implementation must gain a `fetchMetrics()` stub or the interface change breaks it with a fatal "class does not implement abstract method" error)
- Test: `tests/Unit/Domain/Publishing/FakeSocialPublisherMetricsTest.php`

**Interfaces:**
- Produces: `App\Domain\Publishing\VideoMetricsResult` — readonly DTO, constructor `(int $views, int $likes, int $comments, int $shares, ?int $saves = null, ?int $watchTime = null, ?float $completionRate = null, ?int $followersGained = null, array $metadata = [])`.
- Produces: `SocialPublisherInterface::fetchMetrics(Publication $publication): VideoMetricsResult`.
- Produces: `FakeSocialPublisher::respondWithMetrics(VideoMetricsResult $result): static` — lets tests fix a deterministic `fetchMetrics()` return value, mirroring the existing `respondWith()` for `publish()`.
- Consumes (Task 2+): `Publication::videoMetrics()` (`HasMany` to `VideoMetric`, already exists on `app/Models/Publication.php`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Publishing;

use App\Domain\Publishing\Providers\FakeSocialPublisher;
use App\Domain\Publishing\VideoMetricsResult;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\VideoMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FakeSocialPublisherMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_grows_metrics_monotonically_from_the_previous_measurement(): void
    {
        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);
        VideoMetric::factory()->create([
            'publication_id' => $publication->id,
            'views' => 1000,
            'likes' => 100,
            'comments' => 10,
            'shares' => 5,
            'measured_at' => now()->subHour(),
        ]);

        $result = (new FakeSocialPublisher)->fetchMetrics($publication->fresh());

        $this->assertInstanceOf(VideoMetricsResult::class, $result);
        $this->assertGreaterThan(1000, $result->views);
        $this->assertGreaterThan(100, $result->likes);
        $this->assertGreaterThanOrEqual(10, $result->comments);
        $this->assertGreaterThanOrEqual(5, $result->shares);
    }

    public function test_it_starts_from_a_positive_baseline_when_there_is_no_previous_measurement(): void
    {
        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);

        $result = (new FakeSocialPublisher)->fetchMetrics($publication);

        $this->assertGreaterThan(0, $result->views);
        $this->assertGreaterThan(0, $result->likes);
    }

    public function test_respond_with_metrics_overrides_the_generated_result(): void
    {
        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);
        $fixed = new VideoMetricsResult(views: 42, likes: 7, comments: 1, shares: 0);

        $result = (new FakeSocialPublisher)->respondWithMetrics($fixed)->fetchMetrics($publication);

        $this->assertSame($fixed, $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FakeSocialPublisherMetricsTest`
Expected: FAIL — `Call to undefined method App\Domain\Publishing\Providers\FakeSocialPublisher::fetchMetrics()` (and `VideoMetricsResult` class not found)

- [ ] **Step 3: Create the `VideoMetricsResult` DTO**

```php
<?php

namespace App\Domain\Publishing;

final class VideoMetricsResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $views,
        public readonly int $likes,
        public readonly int $comments,
        public readonly int $shares,
        public readonly ?int $saves = null,
        public readonly ?int $watchTime = null,
        public readonly ?float $completionRate = null,
        public readonly ?int $followersGained = null,
        public readonly array $metadata = [],
    ) {}
}
```

- [ ] **Step 4: Add `fetchMetrics()` to the interface**

Edit `app/Domain/Publishing/SocialPublisherInterface.php` to:

```php
<?php

namespace App\Domain\Publishing;

use App\Models\Publication;

interface SocialPublisherInterface
{
    public function publish(Publication $publication): PublishResult;

    public function fetchMetrics(Publication $publication): VideoMetricsResult;
}
```

- [ ] **Step 5: Implement `fetchMetrics()` on `FakeSocialPublisher`**

Edit `app/Domain/Publishing/Providers/FakeSocialPublisher.php` to:

```php
<?php

namespace App\Domain\Publishing\Providers;

use App\Domain\Publishing\PublishResult;
use App\Domain\Publishing\SocialPublisherInterface;
use App\Domain\Publishing\VideoMetricsResult;
use App\Models\Publication;
use Illuminate\Support\Str;

final class FakeSocialPublisher implements SocialPublisherInterface
{
    private ?PublishResult $result = null;

    private ?VideoMetricsResult $metricsResult = null;

    public function respondWith(PublishResult $result): static
    {
        $this->result = $result;

        return $this;
    }

    public function respondWithMetrics(VideoMetricsResult $result): static
    {
        $this->metricsResult = $result;

        return $this;
    }

    public function publish(Publication $publication): PublishResult
    {
        return $this->result ?? new PublishResult(
            externalPostId: 'fake-'.Str::uuid(),
            metadata: ['platform' => $publication->socialAccount->platform->value],
        );
    }

    public function fetchMetrics(Publication $publication): VideoMetricsResult
    {
        if ($this->metricsResult !== null) {
            return $this->metricsResult;
        }

        $previous = $publication->videoMetrics()->latest('measured_at')->first();

        return new VideoMetricsResult(
            views: ($previous->views ?? 0) + random_int(50, 5000),
            likes: ($previous->likes ?? 0) + random_int(5, 500),
            comments: ($previous->comments ?? 0) + random_int(0, 50),
            shares: ($previous->shares ?? 0) + random_int(0, 20),
        );
    }
}
```

- [ ] **Step 6: Fix the now-broken `PublishVideoJobTest` anonymous implementation**

In `tests/Feature/Jobs/PublishVideoJobTest.php`, add the `use` import and the stub method to the inline anonymous class inside `test_calling_handle_twice_does_not_publish_twice()`:

```php
use App\Domain\Publishing\VideoMetricsResult;
```

(add alongside the existing `use App\Domain\Publishing\PublishResult;` import at the top of the file), then change the anonymous class body from:

```php
        $this->app->bind(SocialPublisherInterface::class, function () use ($counter) {
            return new class($counter) implements SocialPublisherInterface
            {
                public function __construct(private object $counter) {}

                public function publish(Publication $publication): PublishResult
                {
                    $this->counter->callCount++;

                    return new PublishResult(externalPostId: 'ext-1');
                }
            };
        });
```

to:

```php
        $this->app->bind(SocialPublisherInterface::class, function () use ($counter) {
            return new class($counter) implements SocialPublisherInterface
            {
                public function __construct(private object $counter) {}

                public function publish(Publication $publication): PublishResult
                {
                    $this->counter->callCount++;

                    return new PublishResult(externalPostId: 'ext-1');
                }

                public function fetchMetrics(Publication $publication): VideoMetricsResult
                {
                    throw new \LogicException('fetchMetrics is not exercised by this test.');
                }
            };
        });
```

- [ ] **Step 7: Run both test files to verify everything passes**

Run: `php artisan test --filter=FakeSocialPublisherMetricsTest` then `php artisan test --filter=PublishVideoJobTest`
Expected: PASS for both

- [ ] **Step 8: Commit**

```bash
git add app/Domain/Publishing/VideoMetricsResult.php app/Domain/Publishing/SocialPublisherInterface.php app/Domain/Publishing/Providers/FakeSocialPublisher.php tests/Feature/Jobs/PublishVideoJobTest.php tests/Unit/Domain/Publishing/FakeSocialPublisherMetricsTest.php
git commit -m "Add SocialPublisherInterface::fetchMetrics() and VideoMetricsResult DTO"
```

---

### Task 2: `CollectVideoMetricsJob`

**Files:**
- Modify: `config/logging.php` (add an `analytics` channel, same shape as the existing `content`/`video`/`publishing`/`ai` channels)
- Create: `app/Jobs/CollectVideoMetricsJob.php`
- Test: `tests/Feature/Jobs/CollectVideoMetricsJobTest.php`

**Interfaces:**
- Consumes: `SocialPublisherInterface::fetchMetrics(Publication $publication): VideoMetricsResult` (Task 1), `FakeSocialPublisher::respondWithMetrics()` (Task 1), `NotifiesOnPermanentFailure::notifyPermanentFailure(string $logChannel, string $message, array $context)` (existing, `app/Jobs/Concerns/NotifiesOnPermanentFailure.php`).
- Produces: `App\Jobs\CollectVideoMetricsJob(int $publicationId)` — public readonly `publicationId` property, used by Task 3's command.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Jobs;

use App\Domain\Publishing\Providers\FakeSocialPublisher;
use App\Domain\Publishing\SocialPublisherInterface;
use App\Domain\Publishing\VideoMetricsResult;
use App\Jobs\CollectVideoMetricsJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use App\Models\VideoMetric;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CollectVideoMetricsJobTest extends TestCase
{
    use RefreshDatabase;

    private function bindFakePublisher(?VideoMetricsResult $result = null): void
    {
        $this->app->bind(SocialPublisherInterface::class, function () use ($result) {
            $fake = new FakeSocialPublisher;

            return $result !== null ? $fake->respondWithMetrics($result) : $fake;
        });
    }

    public function test_it_creates_a_video_metric_for_a_published_publication(): void
    {
        $this->bindFakePublisher(new VideoMetricsResult(views: 1000, likes: 100, comments: 10, shares: 5));

        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);

        app()->call([new CollectVideoMetricsJob($publication->id), 'handle']);

        $metric = VideoMetric::where('publication_id', $publication->id)->sole();

        $this->assertSame(1000, $metric->views);
        $this->assertSame(100, $metric->likes);
        $this->assertSame(10, $metric->comments);
        $this->assertSame(5, $metric->shares);
        $this->assertNotNull($metric->measured_at);
    }

    public function test_it_is_a_no_op_for_a_publication_that_is_not_published(): void
    {
        $this->bindFakePublisher();

        $publication = Publication::factory()->create(['status' => PublicationStatus::Scheduled]);

        app()->call([new CollectVideoMetricsJob($publication->id), 'handle']);

        $this->assertSame(0, VideoMetric::where('publication_id', $publication->id)->count());
    }

    public function test_repeated_runs_append_new_rows_instead_of_overwriting(): void
    {
        $this->bindFakePublisher(new VideoMetricsResult(views: 10, likes: 1, comments: 0, shares: 0));

        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);

        app()->call([new CollectVideoMetricsJob($publication->id), 'handle']);
        app()->call([new CollectVideoMetricsJob($publication->id), 'handle']);

        $this->assertSame(2, VideoMetric::where('publication_id', $publication->id)->count());
    }

    public function test_failed_sends_a_permanent_failure_notification(): void
    {
        Notification::fake();
        User::factory()->create();

        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);

        $job = new CollectVideoMetricsJob($publication->id);
        $job->failed(new \RuntimeException('API unavailable'));

        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->context['publication_id'] === $publication->id
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CollectVideoMetricsJobTest`
Expected: FAIL — `Class "App\Jobs\CollectVideoMetricsJob" not found`

- [ ] **Step 3: Add the `analytics` logging channel**

In `config/logging.php`, inside the `channels` array, add (next to the existing `ai` block):

```php
        'analytics' => [
            'driver' => 'daily',
            'path' => storage_path('logs/analytics.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
        ],
```

- [ ] **Step 4: Implement `CollectVideoMetricsJob`**

```php
<?php

namespace App\Jobs;

use App\Domain\Publishing\SocialPublisherInterface;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\VideoMetric;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class CollectVideoMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public function __construct(public readonly int $publicationId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60, 120];
    }

    public function handle(SocialPublisherInterface $publisher): void
    {
        $publication = Publication::findOrFail($this->publicationId);

        if ($publication->status !== PublicationStatus::Published) {
            return;
        }

        $result = $publisher->fetchMetrics($publication);

        VideoMetric::create([
            'publication_id' => $publication->id,
            'views' => $result->views,
            'likes' => $result->likes,
            'comments' => $result->comments,
            'shares' => $result->shares,
            'saves' => $result->saves,
            'watch_time' => $result->watchTime,
            'completion_rate' => $result->completionRate,
            'followers_gained' => $result->followersGained,
            'metadata' => $result->metadata,
            'measured_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->notifyPermanentFailure('analytics', 'Metrics collection failed permanently.', [
            'publication_id' => $this->publicationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=CollectVideoMetricsJobTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add config/logging.php app/Jobs/CollectVideoMetricsJob.php tests/Feature/Jobs/CollectVideoMetricsJobTest.php
git commit -m "Add CollectVideoMetricsJob"
```

---

### Task 3: `metrics:collect` command + scheduler registration

**Files:**
- Create: `app/Console/Commands/CollectMetricsCommand.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Console/CollectMetricsCommandTest.php`

**Interfaces:**
- Consumes: `CollectVideoMetricsJob(int $publicationId)` (Task 2).
- Produces: `metrics:collect` Artisan command signature, scheduled hourly.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Console;

use App\Jobs\CollectVideoMetricsJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CollectMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_collect_video_metrics_job_only_for_published_publications(): void
    {
        Queue::fake();

        $published = Publication::factory()->create(['status' => PublicationStatus::Published]);
        Publication::factory()->create(['status' => PublicationStatus::Scheduled]);
        Publication::factory()->create(['status' => PublicationStatus::Draft]);

        Artisan::call('metrics:collect');

        Queue::assertPushed(CollectVideoMetricsJob::class, 1);
        Queue::assertPushed(CollectVideoMetricsJob::class, fn (CollectVideoMetricsJob $job): bool => $job->publicationId === $published->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CollectMetricsCommandTest`
Expected: FAIL — command `metrics:collect` does not exist

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use App\Jobs\CollectVideoMetricsJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use Illuminate\Console\Command;

class CollectMetricsCommand extends Command
{
    protected $signature = 'metrics:collect';

    protected $description = 'Dispatch CollectVideoMetricsJob for every published publication.';

    public function handle(): int
    {
        $published = Publication::where('status', PublicationStatus::Published)->get();

        foreach ($published as $publication) {
            CollectVideoMetricsJob::dispatch($publication->id);
        }

        $this->info("Dispatched {$published->count()} metrics collection job(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register the scheduler tick**

In `bootstrap/app.php`, change:

```php
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('publications:dispatch-due')
            ->everyMinute()
            ->withoutOverlapping();
    })
```

to:

```php
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('publications:dispatch-due')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('metrics:collect')
            ->hourly()
            ->withoutOverlapping();
    })
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=CollectMetricsCommandTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/CollectMetricsCommand.php bootstrap/app.php tests/Feature/Console/CollectMetricsCommandTest.php
git commit -m "Add metrics:collect command and hourly scheduler tick"
```

---

### Task 4: `VideoMetric::latestPerPublication()` scope

**Files:**
- Modify: `app/Models/VideoMetric.php`
- Test: `tests/Feature/Models/VideoMetricTest.php`

**Interfaces:**
- Produces: `VideoMetric::latestPerPublication(): Illuminate\Database\Eloquent\Builder` — static query scope returning exactly one row per `publication_id`, the one with the greatest `measured_at`. Consumed by Task 6 (`PipelineStatsWidget`), Task 7 (`BestVideosWidget`), Task 8 (`TopTopicsWidget`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Models;

use App\Models\Publication;
use App\Models\VideoMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoMetricTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_per_publication_returns_only_the_most_recent_row_per_publication(): void
    {
        $publicationA = Publication::factory()->create();
        $publicationB = Publication::factory()->create();

        VideoMetric::factory()->create([
            'publication_id' => $publicationA->id,
            'views' => 100,
            'measured_at' => now()->subHour(),
        ]);
        $latestA = VideoMetric::factory()->create([
            'publication_id' => $publicationA->id,
            'views' => 200,
            'measured_at' => now(),
        ]);
        $latestB = VideoMetric::factory()->create([
            'publication_id' => $publicationB->id,
            'views' => 50,
            'measured_at' => now(),
        ]);

        $results = VideoMetric::latestPerPublication()->get();

        $this->assertCount(2, $results);
        $this->assertEqualsCanonicalizing(
            [$latestA->id, $latestB->id],
            $results->pluck('id')->all()
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VideoMetricTest`
Expected: FAIL — `Call to undefined method App\Models\VideoMetric::latestPerPublication()`

- [ ] **Step 3: Add the scope**

In `app/Models/VideoMetric.php`, add the `Builder` import and the method:

```php
use Illuminate\Database\Eloquent\Builder;
```

```php
    public static function latestPerPublication(): Builder
    {
        return static::query()
            ->selectRaw('DISTINCT ON (publication_id) *')
            ->orderBy('publication_id')
            ->orderByDesc('measured_at');
    }
```

(Add it as a public static method on the `VideoMetric` class, alongside the existing `publication()` relation.)

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=VideoMetricTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Models/VideoMetric.php tests/Feature/Models/VideoMetricTest.php
git commit -m "Add VideoMetric::latestPerPublication() scope"
```

---

### Task 5: `VideoMetricResource` becomes fully view-only

**Files:**
- Modify: `app/Filament/Resources/VideoMetrics/VideoMetricResource.php`
- Modify: `app/Filament/Resources/VideoMetrics/Tables/VideoMetricsTable.php`
- Modify: `app/Filament/Resources/VideoMetrics/Pages/ListVideoMetrics.php`
- Delete: `app/Filament/Resources/VideoMetrics/Pages/CreateVideoMetric.php`
- Delete: `app/Filament/Resources/VideoMetrics/Pages/EditVideoMetric.php`
- Test: `tests/Feature/Filament/VideoMetricResourceViewOnlyTest.php`

`VideoMetricResource::canCreate()` is already `false` (set in an earlier phase), but the `create`/`edit` routes and the table's `EditAction` are still reachable — this task finishes that transition, matching the fully-removed-routes pattern already used by `ScriptResource`.

**Interfaces:** none (Filament wiring only; no new PHP types consumed by later tasks).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use App\Models\VideoMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoMetricResourceViewOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_create_route_no_longer_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/video-metrics/create')->assertNotFound();
    }

    public function test_the_edit_route_no_longer_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);
        $metric = VideoMetric::factory()->create(['publication_id' => $publication->id]);

        $this->get("/admin/video-metrics/{$metric->id}/edit")->assertNotFound();
    }

    public function test_the_list_page_shows_metrics(): void
    {
        $this->actingAs(User::factory()->create());

        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);
        // Under 1000: VideoMetricsTable's pre-existing `views` column uses ->numeric(),
        // which formats via Number::format() under the app locale and inserts a
        // thousands separator (e.g. 4242 -> "4,242") — a 3-digit value avoids that.
        VideoMetric::factory()->create(['publication_id' => $publication->id, 'views' => 999]);

        $this->get('/admin/video-metrics')->assertSuccessful()->assertSee('999');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VideoMetricResourceViewOnlyTest`
Expected: FAIL — `test_the_create_route_no_longer_exists` and `test_the_edit_route_no_longer_exists` currently get a 403 (canCreate() gate) or a real edit page (200), not a 404, because the routes still exist

- [ ] **Step 3: Delete the Create/Edit pages**

Delete `app/Filament/Resources/VideoMetrics/Pages/CreateVideoMetric.php` and `app/Filament/Resources/VideoMetrics/Pages/EditVideoMetric.php`.

- [ ] **Step 4: Update the resource's `getPages()`**

Edit `app/Filament/Resources/VideoMetrics/VideoMetricResource.php`: remove the `use App\Filament\Resources\VideoMetrics\Pages\CreateVideoMetric;` and `use App\Filament\Resources\VideoMetrics\Pages\EditVideoMetric;` imports, and change `getPages()` to:

```php
    public static function getPages(): array
    {
        return [
            'index' => ListVideoMetrics::route('/'),
        ];
    }
```

- [ ] **Step 5: Drop the header create action and the table's edit action**

Edit `app/Filament/Resources/VideoMetrics/Pages/ListVideoMetrics.php` to:

```php
<?php

namespace App\Filament\Resources\VideoMetrics\Pages;

use App\Filament\Resources\VideoMetrics\VideoMetricResource;
use Filament\Resources\Pages\ListRecords;

class ListVideoMetrics extends ListRecords
{
    protected static string $resource = VideoMetricResource::class;
}
```

Edit `app/Filament/Resources/VideoMetrics/Tables/VideoMetricsTable.php`: remove the `use Filament\Actions\EditAction;` import and the `EditAction::make(),` line, so `->recordActions([])` is empty:

```php
            ->recordActions([
                //
            ])
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=VideoMetricResourceViewOnlyTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Resources/VideoMetrics tests/Feature/Filament/VideoMetricResourceViewOnlyTest.php
git commit -m "Make VideoMetricResource fully view-only"
```

---

### Task 6: `PipelineStatsWidget`

**Files:**
- Create: `app/Filament/Widgets/PipelineStatsWidget.php`
- Test: `tests/Feature/Filament/PipelineStatsWidgetTest.php`

**Interfaces:**
- Consumes: `VideoMetric::latestPerPublication()` (Task 4).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\PipelineStatsWidget;
use App\Models\Enums\PublicationStatus;
use App\Models\Enums\VideoStatus;
use App\Models\Publication;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoMetric;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PipelineStatsWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_pipeline_and_engagement_totals(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Two videos rendered "today", one backdated 2 days — proves the
        // "Videos generated today" stat actually filters by date instead of
        // counting every Rendered video.
        Video::factory()->create(['status' => VideoStatus::Rendered]);
        $renderedToday = Video::factory()->create(['status' => VideoStatus::Rendered]);
        $renderedOld = Video::factory()->create(['status' => VideoStatus::Rendered]);
        Video::whereKey($renderedOld->id)->update(['updated_at' => now()->subDays(2)]);

        // 3 bare Published publications + 1 with a VideoMetric = 4 published total.
        Publication::factory()->count(3)->create(['status' => PublicationStatus::Published]);
        $metricPublication = Publication::factory()->create(['status' => PublicationStatus::Published]);
        VideoMetric::factory()->create([
            'publication_id' => $metricPublication->id,
            'views' => 55555,
            'likes' => 6666,
            'comments' => 77,
            'measured_at' => now(),
        ]);

        // 4 failure notifications sent "now", one of them backdated 2 days —
        // proves "Failed jobs today" filters by date too (expected count: 3).
        for ($i = 0; $i < 4; $i++) {
            Notification::send($user, new PipelineJobFailedNotification('boom', ['attempt' => $i]));
        }
        $backdated = $user->notifications()->latest()->first();
        $backdated->forceFill(['created_at' => now()->subDays(2)])->save();

        // Every asserted number below uses a distinct repeated digit (2, 4, 3, 5, 6, 7)
        // so a wrong stat can't accidentally satisfy another stat's assertion.
        Livewire::test(PipelineStatsWidget::class)
            ->assertSee('2') // Videos generated today
            ->assertSee('4') // Videos published (cumulative)
            ->assertSee('3') // Failed jobs today
            ->assertSee('55555') // Views
            ->assertSee('6666') // Likes
            ->assertSee('77'); // Comments
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PipelineStatsWidgetTest`
Expected: FAIL — `Class "App\Filament\Widgets\PipelineStatsWidget" not found`

- [ ] **Step 3: Implement the widget**

```php
<?php

namespace App\Filament\Widgets;

use App\Models\Enums\PublicationStatus;
use App\Models\Enums\VideoStatus;
use App\Models\Publication;
use App\Models\Video;
use App\Models\VideoMetric;
use App\Notifications\PipelineJobFailedNotification;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Notifications\DatabaseNotification;

class PipelineStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $latestMetrics = VideoMetric::latestPerPublication()->get();

        return [
            Stat::make('Videos generated today', Video::where('status', VideoStatus::Rendered)
                ->whereDate('updated_at', today())
                ->count()),
            Stat::make('Videos published', Publication::where('status', PublicationStatus::Published)->count()),
            Stat::make('Failed jobs today', DatabaseNotification::where('type', PipelineJobFailedNotification::class)
                ->whereDate('created_at', today())
                ->count()),
            Stat::make('Views', (string) $latestMetrics->sum('views')),
            Stat::make('Likes', (string) $latestMetrics->sum('likes')),
            Stat::make('Comments', (string) $latestMetrics->sum('comments')),
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=PipelineStatsWidgetTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Widgets/PipelineStatsWidget.php tests/Feature/Filament/PipelineStatsWidgetTest.php
git commit -m "Add PipelineStatsWidget to the Dashboard"
```

---

### Task 7: `BestVideosWidget`

**Files:**
- Create: `app/Filament/Widgets/BestVideosWidget.php`
- Test: `tests/Feature/Filament/BestVideosWidgetTest.php`

**Interfaces:**
- Consumes: `VideoMetric::latestPerPublication()` (Task 4).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\BestVideosWidget;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BestVideosWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ranks_publications_by_latest_views_descending(): void
    {
        $this->actingAs(User::factory()->create());

        // Created in reverse of the expected sort order — Low Video first — so
        // a query that dropped its `orderByDesc` and fell back to insertion/scan
        // order would produce ['Low Video', 'Top Video'] and fail this assertion,
        // instead of accidentally passing.
        $lowVideo = Video::factory()->create(['title' => 'Low Video']);
        $lowPublication = Publication::factory()->create([
            'video_id' => $lowVideo->id,
            'status' => PublicationStatus::Published,
        ]);
        VideoMetric::factory()->create([
            'publication_id' => $lowPublication->id,
            'views' => 10,
            'measured_at' => now(),
        ]);

        $topVideo = Video::factory()->create(['title' => 'Top Video']);
        $topPublication = Publication::factory()->create([
            'video_id' => $topVideo->id,
            'status' => PublicationStatus::Published,
        ]);
        VideoMetric::factory()->create([
            'publication_id' => $topPublication->id,
            'views' => 9000,
            'measured_at' => now(),
        ]);

        Livewire::test(BestVideosWidget::class)
            ->assertSeeHtmlInOrder(['Top Video', 'Low Video']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BestVideosWidgetTest`
Expected: FAIL — `Class "App\Filament\Widgets\BestVideosWidget" not found`

- [ ] **Step 3: Implement the widget**

```php
<?php

namespace App\Filament\Widgets;

use App\Models\Publication;
use App\Models\VideoMetric;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class BestVideosWidget extends TableWidget
{
    protected static ?string $heading = 'Best Videos';

    public function table(Table $table): Table
    {
        $latestMetrics = VideoMetric::latestPerPublication();

        return $table
            ->query(
                Publication::query()
                    ->joinSub($latestMetrics, 'latest_metrics', 'latest_metrics.publication_id', '=', 'publications.id')
                    ->join('videos', 'videos.id', '=', 'publications.video_id')
                    ->join('social_accounts', 'social_accounts.id', '=', 'publications.social_account_id')
                    ->orderByDesc('latest_metrics.views')
                    ->limit(5)
                    ->select([
                        'publications.id',
                        'videos.title as video_title',
                        'social_accounts.platform',
                        'latest_metrics.views',
                        'latest_metrics.likes',
                        'latest_metrics.comments',
                    ])
            )
            ->columns([
                TextColumn::make('video_title')->label('Video'),
                TextColumn::make('platform')->badge(),
                TextColumn::make('views')->numeric(),
                TextColumn::make('likes')->numeric(),
                TextColumn::make('comments')->numeric(),
            ])
            ->paginated(false);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=BestVideosWidgetTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Widgets/BestVideosWidget.php tests/Feature/Filament/BestVideosWidgetTest.php
git commit -m "Add BestVideosWidget to the Dashboard"
```

---

### Task 8: `TopTopicsWidget`

**Files:**
- Create: `app/Filament/Widgets/TopTopicsWidget.php`
- Test: `tests/Feature/Filament/TopTopicsWidgetTest.php`

**Interfaces:**
- Consumes: `VideoMetric::latestPerPublication()` (Task 4), `ContentIdea.topic` (existing column), `Video.content_idea_id` (existing column).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\TopTopicsWidget;
use App\Models\ContentIdea;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TopTopicsWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ranks_topics_by_summed_latest_views_descending(): void
    {
        $this->actingAs(User::factory()->create());

        $hotIdea = ContentIdea::factory()->create(['topic' => 'AI News']);
        $hotVideo = Video::factory()->create(['content_idea_id' => $hotIdea->id]);
        $hotPublication = Publication::factory()->create([
            'video_id' => $hotVideo->id,
            'status' => PublicationStatus::Published,
        ]);
        VideoMetric::factory()->create([
            'publication_id' => $hotPublication->id,
            'views' => 5000,
            'measured_at' => now(),
        ]);

        $coldIdea = ContentIdea::factory()->create(['topic' => 'Gardening Tips']);
        $coldVideo = Video::factory()->create(['content_idea_id' => $coldIdea->id]);
        $coldPublication = Publication::factory()->create([
            'video_id' => $coldVideo->id,
            'status' => PublicationStatus::Published,
        ]);
        VideoMetric::factory()->create([
            'publication_id' => $coldPublication->id,
            'views' => 20,
            'measured_at' => now(),
        ]);

        Livewire::test(TopTopicsWidget::class)
            ->assertSeeHtmlInOrder(['AI News', 'Gardening Tips']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TopTopicsWidgetTest`
Expected: FAIL — `Class "App\Filament\Widgets\TopTopicsWidget" not found`

- [ ] **Step 3: Implement the widget**

```php
<?php

namespace App\Filament\Widgets;

use App\Models\ContentIdea;
use App\Models\VideoMetric;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TopTopicsWidget extends TableWidget
{
    protected static ?string $heading = 'Top Performing Topics';

    public function table(Table $table): Table
    {
        $latestMetrics = VideoMetric::latestPerPublication();

        return $table
            ->query(
                ContentIdea::query()
                    ->join('videos', 'videos.content_idea_id', '=', 'content_ideas.id')
                    ->join('publications', 'publications.video_id', '=', 'videos.id')
                    ->joinSub($latestMetrics, 'latest_metrics', 'latest_metrics.publication_id', '=', 'publications.id')
                    ->selectRaw('content_ideas.topic as id, content_ideas.topic, SUM(latest_metrics.views) as total_views')
                    ->groupBy('content_ideas.topic')
                    ->orderByDesc('total_views')
                    ->limit(5)
            )
            ->columns([
                TextColumn::make('topic'),
                TextColumn::make('total_views')->numeric(),
            ])
            ->paginated(false);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=TopTopicsWidgetTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Widgets/TopTopicsWidget.php tests/Feature/Filament/TopTopicsWidgetTest.php
git commit -m "Add TopTopicsWidget to the Dashboard"
```

---

### Task 9: `LlmUsageReport` page

**Files:**
- Create: `app/Filament/Pages/LlmUsageReport.php`
- Create: `resources/views/filament/pages/llm-usage-report.blade.php`
- Test: `tests/Feature/Filament/LlmUsageReportTest.php`

**Interfaces:** none consumed beyond the existing `App\Models\LlmUsageLog` (fields: `provider`, `model`, `purpose`, `prompt_tokens`, `completion_tokens`, `cost`, `status` — all already present since Phase 1).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\LlmUsageReport;
use App\Models\Enums\LlmUsageLogStatus;
use App\Models\LlmUsageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LlmUsageReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_groups_usage_by_provider_model_and_purpose(): void
    {
        $this->actingAs(User::factory()->create());

        LlmUsageLog::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'purpose' => 'idea',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'cost' => 0.01,
            'status' => LlmUsageLogStatus::Success,
        ]);

        LlmUsageLog::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'purpose' => 'idea',
            'prompt_tokens' => 200,
            'completion_tokens' => 100,
            'cost' => 0.02,
            'status' => LlmUsageLogStatus::Failed,
        ]);

        LlmUsageLog::factory()->create([
            'provider' => 'anthropic',
            'model' => 'claude-opus-4',
            'purpose' => 'script',
            'prompt_tokens' => 500,
            'completion_tokens' => 300,
            'cost' => 1.5,
            'status' => LlmUsageLogStatus::Success,
        ]);

        Livewire::test(LlmUsageReport::class)
            ->assertSeeHtmlInOrder(['anthropic', 'openai'])
            ->assertSee('300')
            ->assertSee('50%');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LlmUsageReportTest`
Expected: FAIL — `Class "App\Filament\Pages\LlmUsageReport" not found`

- [ ] **Step 3: Implement the page**

```php
<?php

namespace App\Filament\Pages;

use App\Models\LlmUsageLog;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

class LlmUsageReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?string $title = 'LLM Usage Report';

    protected string $view = 'filament.pages.llm-usage-report';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                LlmUsageLog::query()
                    ->selectRaw(
                        "provider || '-' || model || '-' || purpose as id,
                        provider,
                        model,
                        purpose,
                        COUNT(*) as calls,
                        SUM(prompt_tokens) as prompt_tokens,
                        SUM(completion_tokens) as completion_tokens,
                        SUM(prompt_tokens + completion_tokens) as total_tokens,
                        SUM(cost) as total_cost,
                        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count"
                    )
                    ->groupBy('provider', 'model', 'purpose')
            )
            ->columns([
                TextColumn::make('provider'),
                TextColumn::make('model'),
                TextColumn::make('purpose'),
                TextColumn::make('calls')->numeric(),
                TextColumn::make('prompt_tokens')->numeric(),
                TextColumn::make('completion_tokens')->numeric(),
                TextColumn::make('total_tokens')->numeric(),
                TextColumn::make('total_cost')->money(),
                TextColumn::make('success_rate')
                    ->getStateUsing(fn (LlmUsageLog $record): string => $record->calls > 0
                        ? round($record->success_count / $record->calls * 100, 1).'%'
                        : '—'),
            ])
            ->defaultSort('total_cost', 'desc');
    }
}
```

- [ ] **Step 4: Create the Blade view**

```blade
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=LlmUsageReportTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Pages/LlmUsageReport.php resources/views/filament/pages/llm-usage-report.blade.php tests/Feature/Filament/LlmUsageReportTest.php
git commit -m "Add LLM Usage Report page"
```

---

### Task 10: Final verification (DoD)

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: all tests pass, including every test added in Tasks 1–9

- [ ] **Step 2: Run Pint**

Run: `./vendor/bin/pint`
Expected: no style violations (or auto-fixes applied; re-run `php artisan test` if it reformatted anything)

- [ ] **Step 3: Verify routes**

Run: `php artisan route:list`
Expected: `admin/video-metrics` has only an `index` route (no `create`/`edit`), `admin/llm-usage-report` is present, no errors

- [ ] **Step 4: Verify migrations and seeders**

Run: `php artisan migrate:fresh --seed`
Expected: completes without errors (this phase adds no new migrations — `VideoMetric`/`LlmUsageLog` schemas already existed)

- [ ] **Step 5: Verify the scheduler**

Run: `php artisan schedule:list`
Expected: both `publications:dispatch-due` (every minute) and `metrics:collect` (hourly) are listed

- [ ] **Step 6: Manually exercise the Phase 5 scenarios**

Using `php artisan tinker` or the admin panel at `/admin`:
1. Create a `Publication` with `status=Published`, run `php artisan metrics:collect` with `FakeSocialPublisher` bound — confirm a `VideoMetric` row is created.
2. Run `metrics:collect` again on the same publication — confirm a second `VideoMetric` row is added (not an overwrite), with `views` greater than or equal to the first.
3. Open `/admin` — confirm the Dashboard shows `PipelineStatsWidget`, `BestVideosWidget`, and `TopTopicsWidget` with the data seeded above.
4. Open `/admin/llm-usage-report` — confirm it lists the existing `LlmUsageLog` rows grouped by provider/model/purpose.
5. Confirm `/admin/video-metrics/create` and `/admin/video-metrics/{id}/edit` both 404.

- [ ] **Step 7: Update `ROADMAP.md`**

Mark all Phase 5 deliverables `[x]`, fill in the DoD paragraph and Spec/Plan links, following the existing roadmap conventions (see the Phase 4 entry for the exact style), and add a "Для Phase 6+ — врахувати" section listing the `failed_jobs` table gap noted in the spec's §6.

- [ ] **Step 8: Commit**

```bash
git add ROADMAP.md
git commit -m "Mark Phase 5 (Analytics) complete in roadmap"
```
