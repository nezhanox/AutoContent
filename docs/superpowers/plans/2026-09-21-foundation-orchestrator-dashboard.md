# Foundation: Auto-Orchestration + Status Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the existing 7-manual-button video pipeline into a single "Generate Video" action (channel + topic text) that auto-chains through every stage, with per-stage failure visibility and one-click retry in Filament.

**Architecture:** Each of the 7 existing pipeline jobs (`GenerateScriptJob` → `GenerateScenesJob` → `GenerateVoiceoverJob` → `CollectVideoAssetsJob` → `GenerateSubtitlesJob` → `RenderVideoJob` → `QualityCheckVideoJob`) already guards its own preconditions and is idempotent. We add one `dispatch()` call to the end of each job's success path (no `Bus::chain`), add a `failed_stage` column so `failed()` handlers record *which* stage broke, and add Filament UI (a header action to start the chain, a table column to show progress, a retry action) on top of the untouched domain/service layer.

**Tech Stack:** Laravel 12, Filament 4, PostgreSQL, Redis queue (`QUEUE_CONNECTION=sync` in tests — see Global Constraints).

**Spec:** `docs/superpowers/specs/2026-09-21-foundation-orchestrator-dashboard-design.md`

## Global Constraints

- No new composer dependencies — everything here uses Laravel/Filament APIs already in `composer.lock`.
- `phpunit.xml` sets `QUEUE_CONNECTION=sync`. Any test that calls a job's `handle()` directly (not through `Queue::fake()`) executes synchronously in-process. Once a job dispatches the next job in the chain, **every existing test that reaches that job's success path and does not already fake the queue will cascade into the next job's `handle()` for real.** Each task below that adds a `dispatch()` call also adds `Queue::fake()` to that job's test class `setUp()` — this is not optional cleanup, tests will fail or hang without it.
- Match existing code style exactly (run `vendor/bin/pint` before each commit — the codebase is currently 100% clean).
- `failed_stage` is only ever set on a `Video` row for the 6 stages that already have one (`scenes` onward). A `GenerateScriptJob` failure happens before any `Video` exists — it is already visible via `Script.status`/`ContentIdea.status` (existing behavior, untouched) plus the admin notification bell (`PipelineJobFailedNotification`, already wired). This is a known, accepted scope boundary from the spec, not a bug to fix here.
- Run `php artisan test` and `vendor/bin/pint --test` after every task; both must be clean before moving to the next task.

---

### Task 1: `GenerateScriptJob` dispatches `GenerateScenesJob` on success

**Files:**
- Modify: `app/Jobs/GenerateScriptJob.php`
- Test: `tests/Feature/Jobs/GenerateScriptJobTest.php`

**Interfaces:**
- Consumes: `GenerateScenesJob::dispatch(int $scriptId)` (existing constructor, unchanged)
- Produces: nothing new for other tasks to consume — this is the first link in the chain

- [ ] **Step 1: Add a failing assertion to the existing success test**

Open `tests/Feature/Jobs/GenerateScriptJobTest.php`. Add the `Queue` facade import and fake the queue in `setUp()` so this and every other test in the class stop cascading into a real `GenerateScenesJob` execution:

```php
use App\Jobs\GenerateScenesJob;
use Illuminate\Support\Facades\Queue;
```

```php
class GenerateScriptJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_it_generates_a_completed_script_and_marks_the_idea_used(): void
    {
        // ... existing body unchanged ...
    }
```

At the end of `test_it_generates_a_completed_script_and_marks_the_idea_used` (after its existing assertions), add:

```php
        $script = Script::where('content_idea_id', $idea->id)->sole();
        Queue::assertPushed(GenerateScenesJob::class, fn (GenerateScenesJob $job) => $job->scriptId === $script->id);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter test_it_generates_a_completed_script_and_marks_the_idea_used`
Expected: FAIL — `Queue::assertPushed` finds no matching job, because `GenerateScriptJob::handle()` doesn't dispatch anything yet.

- [ ] **Step 3: Dispatch the next job on success**

In `app/Jobs/GenerateScriptJob.php`, the `handle()` method currently ends with:

```php
        $idea->update(['status' => ContentIdeaStatus::Used]);
    }
```

Change it to:

```php
        $idea->update(['status' => ContentIdeaStatus::Used]);

        GenerateScenesJob::dispatch($script->id);
    }
```

No new import is needed — `GenerateScenesJob` is in the same `App\Jobs` namespace.

- [ ] **Step 4: Run the full job test file to verify it passes**

Run: `php artisan test tests/Feature/Jobs/GenerateScriptJobTest.php`
Expected: PASS, all tests (the `setUp()` fake protects the other tests from any behavior change).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/GenerateScriptJob.php tests/Feature/Jobs/GenerateScriptJobTest.php
git commit -m "Chain script generation into scene generation on success"
```

---

### Task 2: `failed_stage` column + `Video::currentStageLabel()` + `GenerateScenesJob` chains into `GenerateVoiceoverJob`

**Files:**
- Create: `database/migrations/2026_09_21_000001_add_failed_stage_to_videos_table.php`
- Modify: `app/Models/Video.php`
- Modify: `app/Jobs/GenerateScenesJob.php`
- Test: `tests/Feature/VideoDomainModelsTest.php`
- Test: `tests/Feature/Jobs/GenerateScenesJobTest.php`

**Interfaces:**
- Produces: `Video::currentStageLabel(): string` — used by Task 11's table column. `Video.failed_stage` (nullable string column, one of `'scenes'|'voiceover'|'assets'|'subtitles'|'render'|'quality_check'`) — used by Tasks 3-7 (writers) and Task 11 (Retry action reader).

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
        Schema::table('videos', function (Blueprint $table) {
            $table->string('failed_stage')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('failed_stage');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `2026_09_21_000001_add_failed_stage_to_videos_table ... DONE`

- [ ] **Step 3: Write the failing model test**

In `tests/Feature/VideoDomainModelsTest.php`, add at the end of the class (before the final `}`):

```php
    public function test_current_stage_label_reflects_progress_through_the_pipeline(): void
    {
        $video = Video::factory()->create(['status' => VideoStatus::ScriptGenerated]);
        $this->assertSame('Generating voiceover', $video->currentStageLabel());

        $video->update(['status' => VideoStatus::VoiceGenerated]);
        $this->assertSame('Collecting assets', $video->fresh()->currentStageLabel());

        $video->update(['status' => VideoStatus::AssetsReady, 'subtitle_id' => null]);
        $this->assertSame('Generating subtitles', $video->fresh()->currentStageLabel());

        $subtitle = MediaAsset::factory()->create(['type' => MediaAssetType::Subtitle]);
        $video->update(['subtitle_id' => $subtitle->id]);
        $this->assertSame('Rendering', $video->fresh()->currentStageLabel());

        $video->update(['status' => VideoStatus::Rendered, 'quality_report' => null]);
        $this->assertSame('Checking quality', $video->fresh()->currentStageLabel());

        $video->update(['quality_report' => ['checks' => []]]);
        $this->assertSame('Done', $video->fresh()->currentStageLabel());
    }

    public function test_current_stage_label_on_failure_names_the_failed_stage(): void
    {
        $video = Video::factory()->create([
            'status' => VideoStatus::Failed,
            'failed_stage' => 'voiceover',
        ]);

        $this->assertSame('Failed: Voiceover', $video->currentStageLabel());
    }
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `php artisan test --filter test_current_stage_label`
Expected: FAIL — `currentStageLabel` method does not exist on `Video`.

- [ ] **Step 5: Implement `Video::currentStageLabel()`**

In `app/Models/Video.php`, add the constant and method inside the class (after the `casts()` method):

```php
    private const STAGE_LABELS = [
        'scenes' => 'Scenes',
        'voiceover' => 'Voiceover',
        'assets' => 'Assets',
        'subtitles' => 'Subtitles',
        'render' => 'Render',
        'quality_check' => 'Quality check',
    ];

    public function currentStageLabel(): string
    {
        if ($this->status === VideoStatus::Failed) {
            $stage = self::STAGE_LABELS[$this->failed_stage] ?? $this->failed_stage ?? 'unknown';

            return "Failed: {$stage}";
        }

        return match (true) {
            $this->status === VideoStatus::ScriptGenerated => 'Generating voiceover',
            $this->status === VideoStatus::VoiceGenerated => 'Collecting assets',
            $this->status === VideoStatus::AssetsReady && $this->subtitle_id === null => 'Generating subtitles',
            $this->status === VideoStatus::AssetsReady => 'Rendering',
            $this->status === VideoStatus::Rendered && $this->quality_report === null => 'Checking quality',
            $this->status === VideoStatus::Rendered => 'Done',
            default => $this->status->value,
        };
    }
```

Also add `'failed_stage'` to the `$fillable` array at the top of the class (next to `'error_message'`).

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/VideoDomainModelsTest.php`
Expected: PASS.

- [ ] **Step 7: Write the failing test for the chain + failure tracking**

In `tests/Feature/Jobs/GenerateScenesJobTest.php`, add imports:

```php
use App\Jobs\GenerateVoiceoverJob;
use Illuminate\Support\Facades\Queue;
```

Add `setUp()` (this file has none currently):

```php
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }
```

In `test_it_creates_a_video_with_ordered_scenes`, add at its end:

```php
        $video = Video::where('script_id', $script->id)->sole();
        Queue::assertPushed(GenerateVoiceoverJob::class, fn (GenerateVoiceoverJob $job) => $job->videoId === $video->id);
```

In `test_failed_marks_an_existing_video_failed` (this is the one that pre-creates a `Video` and calls `$job->failed(new \RuntimeException('boom'))` directly — not `test_failed_notifies_admins_when_no_video_exists_yet`, which has no `Video` row to assert against), add after the existing `$this->assertSame(VideoStatus::Failed, $video->fresh()->status);` line:

```php
        $this->assertSame('scenes', $video->fresh()->failed_stage);
        $this->assertSame('boom', $video->fresh()->error_message);
```

- [ ] **Step 8: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Jobs/GenerateScenesJobTest.php`
Expected: FAIL on both new assertions (no dispatch yet, `failed_stage`/`error_message` not written yet).

- [ ] **Step 9: Implement the chain dispatch and failure tracking**

In `app/Jobs/GenerateScenesJob.php`, the `handle()` method currently builds `$video` only inside the `DB::transaction()` closure. Change it to capture the video by reference and dispatch after the transaction commits:

```php
        $video = null;

        DB::transaction(function () use ($script, $idea, $scenes, &$video) {
            $video = Video::firstOrCreate(
                ['script_id' => $script->id],
                [
                    'content_project_id' => $idea->content_project_id,
                    'content_idea_id' => $idea->id,
                    'title' => $script->metadata['title'] ?? $idea->title,
                    'description' => $script->hook ?? '',
                    'status' => VideoStatus::ScriptGenerated,
                ]
            );

            $video->scenes()->delete();

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
        });

        GenerateVoiceoverJob::dispatch($video->id);
    }
```

No import is needed for `GenerateVoiceoverJob` — it's in the same `App\Jobs` namespace as `GenerateScenesJob`, so the bare class name resolves directly.

Update `failed()`:

```php
    public function failed(Throwable $exception): void
    {
        $video = Video::where('script_id', $this->scriptId)->first();

        if ($video !== null) {
            $video->update([
                'status' => VideoStatus::Failed,
                'failed_stage' => 'scenes',
                'error_message' => $exception->getMessage(),
            ]);
        }

        $this->notifyPermanentFailure('video', 'Scene generation failed permanently.', [
            'script_id' => $this->scriptId,
            'error' => $exception->getMessage(),
        ]);
    }
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Jobs/GenerateScenesJobTest.php`
Expected: PASS.

- [ ] **Step 11: Run the full suite and pint**

Run: `php artisan test && vendor/bin/pint --test`
Expected: all green (this also re-verifies Task 1 didn't regress).

- [ ] **Step 12: Commit**

```bash
git add database/migrations/2026_09_21_000001_add_failed_stage_to_videos_table.php app/Models/Video.php app/Jobs/GenerateScenesJob.php tests/Feature/VideoDomainModelsTest.php tests/Feature/Jobs/GenerateScenesJobTest.php
git commit -m "Add failed_stage tracking and chain scene generation into voiceover generation"
```

---

### Task 3: `GenerateVoiceoverJob` chains into `CollectVideoAssetsJob`

**Files:**
- Modify: `app/Jobs/GenerateVoiceoverJob.php`
- Test: `tests/Feature/Jobs/GenerateVoiceoverJobTest.php`

**Interfaces:**
- Consumes: `Video.failed_stage`/`error_message` (Task 2)
- Produces: nothing new — next job in chain already exists

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Jobs/GenerateVoiceoverJobTest.php`, add imports:

```php
use App\Jobs\CollectVideoAssetsJob;
use Illuminate\Support\Facades\Queue;
```

This file has no `setUp()` yet (the audio-probe fix earlier only added helper methods). Add one, right after `use RefreshDatabase;`:

```php
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }
```

At the end of `test_it_creates_a_voiceover_and_writes_the_audio_file`, add:

```php
        Queue::assertPushed(CollectVideoAssetsJob::class, fn (CollectVideoAssetsJob $job) => $job->videoId === $video->id);
```

At the end of `test_it_rescales_scene_durations_to_match_the_actual_voiceover_duration`, add the same assertion (using that test's `$video`).

In `test_failed_marks_the_video_failed_and_sends_a_notification`, add after the existing `VideoStatus::Failed` assertion:

```php
        $this->assertSame('voiceover', $video->fresh()->failed_stage);
        $this->assertSame('boom', $video->fresh()->error_message);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Jobs/GenerateVoiceoverJobTest.php`
Expected: FAIL on the three new assertions.

- [ ] **Step 3: Dispatch the next job and record failure**

In `app/Jobs/GenerateVoiceoverJob.php`, change the end of `handle()` from:

```php
            $this->rescaleSceneDurations($video, $result['duration']);

            $video->update(['status' => VideoStatus::VoiceGenerated]);
        });
    }
```

to:

```php
            $this->rescaleSceneDurations($video, $result['duration']);

            $video->update(['status' => VideoStatus::VoiceGenerated]);
        });

        CollectVideoAssetsJob::dispatch($video->id);
    }
```

Change `failed()` from:

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

to:

```php
    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update([
            'status' => VideoStatus::Failed,
            'failed_stage' => 'voiceover',
            'error_message' => $exception->getMessage(),
        ]);

        $this->notifyPermanentFailure('video', 'Voiceover generation failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Jobs/GenerateVoiceoverJobTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/GenerateVoiceoverJob.php tests/Feature/Jobs/GenerateVoiceoverJobTest.php
git commit -m "Chain voiceover generation into asset collection"
```

---

### Task 4: `CollectVideoAssetsJob` chains into `GenerateSubtitlesJob`

**Files:**
- Modify: `app/Jobs/CollectVideoAssetsJob.php`
- Test: `tests/Feature/Jobs/CollectVideoAssetsJobTest.php`

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Jobs/CollectVideoAssetsJobTest.php`, add imports:

```php
use App\Jobs\GenerateSubtitlesJob;
use Illuminate\Support\Facades\Queue;
```

Add `setUp()`:

```php
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }
```

At the end of `test_it_assigns_assets_and_marks_the_video_assets_ready`, add:

```php
        Queue::assertPushed(GenerateSubtitlesJob::class, fn (GenerateSubtitlesJob $job) => $job->videoId === $video->id);
```

In `test_failed_marks_the_video_failed_and_sends_a_notification`, add after the existing `$this->assertSame(VideoStatus::Failed, $video->fresh()->status);` line:

```php
        $this->assertSame('assets', $video->fresh()->failed_stage);
        $this->assertSame('boom', $video->fresh()->error_message);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Jobs/CollectVideoAssetsJobTest.php`
Expected: FAIL on the new assertions.

- [ ] **Step 3: Dispatch the next job and record failure**

In `app/Jobs/CollectVideoAssetsJob.php`, change the end of `handle()` from:

```php
        DB::transaction(function () use ($video, $assignments) {
            foreach ($assignments as $sceneId => $assetId) {
                VideoScene::whereKey($sceneId)->update(['asset_id' => $assetId]);
            }

            $video->update(['status' => VideoStatus::AssetsReady]);
        });
    }
```

to:

```php
        DB::transaction(function () use ($video, $assignments) {
            foreach ($assignments as $sceneId => $assetId) {
                VideoScene::whereKey($sceneId)->update(['asset_id' => $assetId]);
            }

            $video->update(['status' => VideoStatus::AssetsReady]);
        });

        GenerateSubtitlesJob::dispatch($video->id);
    }
```

Change `failed()` from:

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

to:

```php
    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update([
            'status' => VideoStatus::Failed,
            'failed_stage' => 'assets',
            'error_message' => $exception->getMessage(),
        ]);

        $this->notifyPermanentFailure('video', 'Asset collection failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Jobs/CollectVideoAssetsJobTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/CollectVideoAssetsJob.php tests/Feature/Jobs/CollectVideoAssetsJobTest.php
git commit -m "Chain asset collection into subtitle generation"
```

---

### Task 5: `GenerateSubtitlesJob` chains into `RenderVideoJob`

**Files:**
- Modify: `app/Jobs/GenerateSubtitlesJob.php`
- Test: `tests/Feature/Jobs/GenerateSubtitlesJobTest.php`

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Jobs/GenerateSubtitlesJobTest.php`, add imports:

```php
use App\Jobs\RenderVideoJob;
use Illuminate\Support\Facades\Queue;
```

Add `setUp()`:

```php
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }
```

At the end of `test_it_creates_a_subtitle_media_asset_and_links_it_to_the_video`, add:

```php
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job) => $job->videoId === $video->id);
```

In `test_failed_marks_the_video_failed_and_sends_a_notification`, add after the existing `$this->assertSame(VideoStatus::Failed, $video->fresh()->status);` line:

```php
        $this->assertSame('subtitles', $video->fresh()->failed_stage);
        $this->assertSame('boom', $video->fresh()->error_message);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Jobs/GenerateSubtitlesJobTest.php`
Expected: FAIL on the new assertions.

- [ ] **Step 3: Dispatch the next job and record failure**

In `app/Jobs/GenerateSubtitlesJob.php`, change the end of `handle()` from:

```php
        DB::transaction(function () use ($video, $result, $path) {
            $subtitle = MediaAsset::create([
                'type' => MediaAssetType::Subtitle,
                'provider' => 'whisper',
                'path' => $path,
                'mime_type' => 'application/x-subrip',
                'metadata' => array_merge(
                    ['segments' => $result['segments'], 'language' => $result['language']],
                    $result['metadata']
                ),
                'hash' => hash('sha256', $result['srt']),
            ]);

            $video->update(['subtitle_id' => $subtitle->id]);
        });
    }
```

to:

```php
        DB::transaction(function () use ($video, $result, $path) {
            $subtitle = MediaAsset::create([
                'type' => MediaAssetType::Subtitle,
                'provider' => 'whisper',
                'path' => $path,
                'mime_type' => 'application/x-subrip',
                'metadata' => array_merge(
                    ['segments' => $result['segments'], 'language' => $result['language']],
                    $result['metadata']
                ),
                'hash' => hash('sha256', $result['srt']),
            ]);

            $video->update(['subtitle_id' => $subtitle->id]);
        });

        RenderVideoJob::dispatch($video->id);
    }
```

Change `failed()` from:

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

to:

```php
    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update([
            'status' => VideoStatus::Failed,
            'failed_stage' => 'subtitles',
            'error_message' => $exception->getMessage(),
        ]);

        $this->notifyPermanentFailure('video', 'Subtitle generation failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Jobs/GenerateSubtitlesJobTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/GenerateSubtitlesJob.php tests/Feature/Jobs/GenerateSubtitlesJobTest.php
git commit -m "Chain subtitle generation into rendering"
```

---

### Task 6: `RenderVideoJob` chains into `QualityCheckVideoJob`

**Files:**
- Modify: `app/Jobs/RenderVideoJob.php`
- Test: `tests/Feature/Jobs/RenderVideoJobTest.php`

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Jobs/RenderVideoJobTest.php`, add imports:

```php
use App\Jobs\QualityCheckVideoJob;
use Illuminate\Support\Facades\Queue;
```

Add `setUp()`:

```php
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }
```

At the end of `test_it_renders_the_video_and_updates_it_from_the_render_result`, add:

```php
        Queue::assertPushed(QualityCheckVideoJob::class, fn (QualityCheckVideoJob $job) => $job->videoId === $video->id);
```

In `test_failed_marks_the_video_failed_and_sends_a_notification` (this one throws `new \RuntimeException('ffmpeg crashed')`, not `'boom'`), add after the existing `$this->assertSame(VideoStatus::Failed, $video->fresh()->status);` line:

```php
        $this->assertSame('render', $video->fresh()->failed_stage);
        $this->assertSame('ffmpeg crashed', $video->fresh()->error_message);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Jobs/RenderVideoJobTest.php`
Expected: FAIL on the new assertions.

- [ ] **Step 3: Dispatch the next job and record failure**

In `app/Jobs/RenderVideoJob.php`, change the end of `handle()` from:

```php
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
```

to:

```php
        DB::transaction(function () use ($video, $result) {
            $video->update([
                'file_path' => $result->path,
                'duration' => (int) round($result->duration),
                'width' => $result->width,
                'height' => $result->height,
                'status' => VideoStatus::Rendered,
            ]);
        });

        QualityCheckVideoJob::dispatch($video->id);
    }
```

Change `failed()` from:

```php
    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update(['status' => VideoStatus::Failed]);

        $this->notifyPermanentFailure('video', 'Video rendering failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
```

to:

```php
    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update([
            'status' => VideoStatus::Failed,
            'failed_stage' => 'render',
            'error_message' => $exception->getMessage(),
        ]);

        $this->notifyPermanentFailure('video', 'Video rendering failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
```

(leave the rest of `failed()` — the closing `notifyPermanentFailure` call and brace — unchanged)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Jobs/RenderVideoJobTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/RenderVideoJob.php tests/Feature/Jobs/RenderVideoJobTest.php
git commit -m "Chain rendering into quality check"
```

---

### Task 7: `QualityCheckVideoJob` records its own failure (end of chain — no further dispatch)

**Files:**
- Modify: `app/Jobs/QualityCheckVideoJob.php`
- Test: `tests/Feature/Jobs/QualityCheckVideoJobTest.php`

- [ ] **Step 1: Write the failing test**

In `tests/Feature/Jobs/QualityCheckVideoJobTest.php`, in `test_failed_marks_the_video_failed_and_sends_a_notification`, add after the existing `$this->assertSame(VideoStatus::Failed, $video->fresh()->status);` line:

```php
        $this->assertSame('quality_check', $video->fresh()->failed_stage);
        $this->assertSame('boom', $video->fresh()->error_message);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Jobs/QualityCheckVideoJobTest.php`
Expected: FAIL on the new assertion.

- [ ] **Step 3: Record the failed stage**

In `app/Jobs/QualityCheckVideoJob.php`, change `failed()` from:

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

to:

```php
    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update([
            'status' => VideoStatus::Failed,
            'failed_stage' => 'quality_check',
            'error_message' => $exception->getMessage(),
        ]);

        $this->notifyPermanentFailure('video', 'Quality check failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
```

No `dispatch()` is added to `handle()` — this is deliberately the end of the chain (see spec §2: publishing stays a manual, separate action).

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Jobs/QualityCheckVideoJobTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full suite**

Run: `php artisan test && vendor/bin/pint --test`
Expected: all green. This confirms Tasks 1–7 together form a working chain.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/QualityCheckVideoJob.php tests/Feature/Jobs/QualityCheckVideoJobTest.php
git commit -m "Record failed_stage on quality check failure"
```

---

### Task 8: Surface script-stage failures on the Content Ideas table

**Files:**
- Modify: `app/Filament/Resources/ContentIdeas/Tables/ContentIdeasTable.php`
- Test: `tests/Feature/Filament/ContentIdeaActionsTest.php`

**Interfaces:**
- Consumes: `ContentIdea::scripts(): HasMany` (existing relation), `Script.status` (existing column)

This closes the one remaining visibility gap noted in Global Constraints: a `GenerateScriptJob` failure has nowhere to show on the Videos dashboard (no `Video` exists yet), but it already updates `Script.status` — this task just displays that on the table the idea already lives on.

- [ ] **Step 1: Write the failing test**

In `tests/Feature/Filament/ContentIdeaActionsTest.php`, add imports:

```php
use App\Models\Enums\ScriptStatus;
use App\Models\Script;
```

Add a new test method:

```php
    public function test_the_ideas_table_shows_the_related_scripts_status(): void
    {
        $this->actingAs(User::factory()->create());

        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::Approved]);
        Script::factory()->create(['content_idea_id' => $idea->id, 'status' => ScriptStatus::Failed]);

        Livewire::test(\App\Filament\Resources\ContentIdeas\Pages\ListContentIdeas::class)
            ->assertTableColumnStateSet('script_status', 'failed', record: $idea);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter test_the_ideas_table_shows_the_related_scripts_status`
Expected: FAIL — column `script_status` does not exist on the table.

- [ ] **Step 3: Add the column**

In `app/Filament/Resources/ContentIdeas/Tables/ContentIdeasTable.php`, add the column right after the existing `status` `TextColumn`:

```php
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('script_status')
                    ->label('Script Status')
                    ->badge()
                    ->state(fn (ContentIdea $record): ?string => $record->scripts()->latest()->value('status'))
                    ->toggleable(),
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Filament/ContentIdeaActionsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/ContentIdeas/Tables/ContentIdeasTable.php tests/Feature/Filament/ContentIdeaActionsTest.php
git commit -m "Show script status on the content ideas table"
```

---

### Task 9: Channel settings — publish platforms as checkboxes, default voice

**Files:**
- Modify: `app/Filament/Resources/ContentProjects/Schemas/ContentProjectForm.php`
- Test: `tests/Feature/Filament/ContentProjectFormTest.php` (new)

**Interfaces:**
- Produces: `ContentProject.target_platforms` (existing `array`-cast column, now populated through a real form control instead of a disabled raw-JSON text box). `ContentProject.settings['tts']['voice']` (existing jsonb path, already read by `GenerateVoiceoverService::resolveVoiceSettings()` — this task is the first thing that writes it through the UI).

The design spec left open whether `settings.platforms` should be a second field distinct from `target_platforms`. Resolution for this plan: **there is only one field**, `target_platforms` — nothing in the codebase gives "which platforms this channel could publish to" and "which platforms it publishes new videos to by default" different behavior today, so a second field would just be unused duplicate state. If a real difference emerges once publishing is built, split it then.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/ContentProjectFormTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ContentProjects\Pages\EditContentProject;
use App\Models\ContentProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContentProjectFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_target_platforms_and_default_voice_persists_them(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create([
            'target_platforms' => [],
            'settings' => [],
        ]);

        Livewire::test(EditContentProject::class, ['record' => $project->getRouteKey()])
            ->fillForm([
                'target_platforms' => ['tiktok', 'youtube'],
                'settings.tts.voice' => 'JBFqnCBsd6RMkjVDRZzb',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $project->fresh();
        $this->assertSame(['tiktok', 'youtube'], $fresh->target_platforms);
        $this->assertSame('JBFqnCBsd6RMkjVDRZzb', $fresh->settings['tts']['voice']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter test_editing_target_platforms_and_default_voice_persists_them`
Expected: FAIL — `target_platforms` is currently a disabled `TextInput`, so `fillForm` can't set it (or it stays `'[]'`), and `settings.tts.voice` has no field.

- [ ] **Step 3: Replace the disabled text field and add the voice select**

In `app/Filament/Resources/ContentProjects/Schemas/ContentProjectForm.php`, add imports:

```php
use App\Models\Enums\SocialPlatform;
use Filament\Forms\Components\CheckboxList;
```

Replace:

```php
                TextInput::make('target_platforms')
                    ->required()
                    ->default('[]')
                    ->disabled(),
```

with:

```php
                CheckboxList::make('target_platforms')
                    ->label('Publish platforms')
                    ->options(array_combine(
                        array_map(fn (SocialPlatform $platform): string => $platform->value, SocialPlatform::cases()),
                        array_map(fn (SocialPlatform $platform): string => $platform->name, SocialPlatform::cases()),
                    ))
                    ->required(),
```

Add, right after the `TextInput::make('language')` field:

```php
                Select::make('settings.tts.voice')
                    ->label('Default voice (ElevenLabs)')
                    ->options([
                        'JBFqnCBsd6RMkjVDRZzb' => 'George — Warm, Captivating Storyteller',
                        'EXAVITQu4vr4xnSDxMaL' => 'Sarah — Mature, Reassuring, Confident',
                        'CwhRBWXzGAHq8TQ4Fs17' => 'Roger — Laid-Back, Casual, Resonant',
                        'TX3LPaxmHKxFdv7VOQHJ' => 'Liam — Energetic, Social Media Creator',
                        'Xb7hH8MSUJpSbSDYk0k2' => 'Alice — Clear, Engaging Educator',
                    ]),
```

(`Select` is already imported in this file for the AI-settings fields.)

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Filament/ContentProjectFormTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full suite**

Run: `php artisan test && vendor/bin/pint --test`
Expected: all green — this also confirms the Create/Edit Content Project pages still render (no leftover reference to the removed `TextInput` import if it's now unused elsewhere in the file; `TextInput` is still used for `name`/`slug`/`niche`/`status`, so its import stays).

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/ContentProjects/Schemas/ContentProjectForm.php tests/Feature/Filament/ContentProjectFormTest.php
git commit -m "Make channel publish platforms and default voice editable"
```

---

### Task 10: "Generate Video" header action

**Files:**
- Modify: `app/Filament/Resources/Videos/Pages/ListVideos.php`
- Test: `tests/Feature/Filament/VideoGenerateActionTest.php` (new)

**Interfaces:**
- Consumes: `GenerateContentIdeaService::generate(ContentProject $project, string $topic): ContentIdea` (existing, unchanged), `GenerateScriptJob::dispatch(int $contentIdeaId)` (existing)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/VideoGenerateActionTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Jobs\GenerateScriptJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class VideoGenerateActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_video_action_creates_an_approved_idea_and_dispatches_script_generation(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'T', 'topic' => 'ai', 'score' => 50])
            );
        });

        $project = ContentProject::factory()->create(['settings' => []]);

        Livewire::test(ListVideos::class)
            ->callAction('generateVideo', data: [
                'content_project_id' => $project->id,
                'topic' => 'ai',
            ])
            ->assertHasNoActionErrors();

        $idea = ContentIdea::where('content_project_id', $project->id)->sole();
        $this->assertSame(ContentIdeaStatus::Approved, $idea->status);

        Queue::assertPushed(GenerateScriptJob::class, fn (GenerateScriptJob $job) => $job->contentIdeaId === $idea->id);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Filament/VideoGenerateActionTest.php`
Expected: FAIL — action `generateVideo` does not exist on `ListVideos`.

- [ ] **Step 3: Add the header action**

Replace the full contents of `app/Filament/Resources/Videos/Pages/ListVideos.php`:

```php
<?php

namespace App\Filament\Resources\Videos\Pages;

use App\Domain\Content\Services\GenerateContentIdeaService;
use App\Filament\Resources\Videos\VideoResource;
use App\Jobs\GenerateScriptJob;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListVideos extends ListRecords
{
    protected static string $resource = VideoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateVideo')
                ->label('Generate Video')
                ->schema([
                    Select::make('content_project_id')
                        ->label('Channel')
                        ->options(fn (): array => ContentProject::query()->pluck('name', 'id')->all())
                        ->required(),
                    TextInput::make('topic')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        $idea = app(GenerateContentIdeaService::class)->generate(
                            ContentProject::findOrFail($data['content_project_id']),
                            $data['topic'],
                        );

                        $idea->update(['status' => ContentIdeaStatus::Approved]);

                        GenerateScriptJob::dispatch($idea->id);

                        Notification::make()->title('Generation started')->success()->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Generation failed to start')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            CreateAction::make(),
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Filament/VideoGenerateActionTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/Videos/Pages/ListVideos.php tests/Feature/Filament/VideoGenerateActionTest.php
git commit -m "Add a single Generate Video action that starts the whole pipeline"
```

---

### Task 11: Stage column + Retry action on the Videos table

**Files:**
- Modify: `app/Filament/Resources/Videos/Tables/VideosTable.php`
- Test: `tests/Feature/Filament/VideoRetryActionTest.php` (new)

**Interfaces:**
- Consumes: `Video::currentStageLabel()` (Task 2), `Video.failed_stage` (Task 2)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/VideoRetryActionTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Jobs\CollectVideoAssetsJob;
use App\Models\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class VideoRetryActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_resets_status_and_redispatches_the_failed_stages_job(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $video = Video::factory()->create([
            'status' => VideoStatus::Failed,
            'failed_stage' => 'assets',
            'error_message' => 'boom',
        ]);

        Livewire::test(ListVideos::class)
            ->callTableAction('retry', $video)
            ->assertHasNoTableActionErrors();

        $fresh = $video->fresh();
        $this->assertSame(VideoStatus::VoiceGenerated, $fresh->status);
        $this->assertNull($fresh->failed_stage);
        $this->assertNull($fresh->error_message);

        Queue::assertPushed(CollectVideoAssetsJob::class, fn (CollectVideoAssetsJob $job) => $job->videoId === $video->id);
    }

    public function test_retry_action_is_not_visible_on_a_non_failed_video(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::Rendered]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('retry', $video);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Filament/VideoRetryActionTest.php`
Expected: FAIL — no `stage` column, no `retry` action.

- [ ] **Step 3: Add the stage column and retry action**

In `app/Filament/Resources/Videos/Tables/VideosTable.php`, add imports:

```php
use App\Jobs\GenerateScenesJob;
```

(`GenerateVoiceoverJob`, `CollectVideoAssetsJob`, `GenerateSubtitlesJob`, `RenderVideoJob`, `QualityCheckVideoJob` are already imported in this file.)

Add the `stage` column right after the existing `status` column:

```php
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('stage')
                    ->label('Stage')
                    ->state(fn (Video $record): string => $record->currentStageLabel())
                    ->badge()
                    ->color(fn (Video $record): string => match (true) {
                        $record->status === VideoStatus::Failed => 'danger',
                        $record->status === VideoStatus::Rendered && $record->quality_report !== null => 'success',
                        default => 'warning',
                    }),
```

Add the `retry` action right before `EditAction::make(),` in `recordActions`:

```php
                Action::make('retry')
                    ->label('Retry')
                    ->color('warning')
                    ->visible(fn (Video $record): bool => $record->status === VideoStatus::Failed)
                    ->requiresConfirmation()
                    ->action(function (Video $record): void {
                        $stage = $record->failed_stage;

                        $record->update([
                            'status' => match ($stage) {
                                'scenes', 'voiceover' => VideoStatus::ScriptGenerated,
                                'assets' => VideoStatus::VoiceGenerated,
                                'subtitles', 'render' => VideoStatus::AssetsReady,
                                'quality_check' => VideoStatus::Rendered,
                                default => $record->status,
                            },
                            'failed_stage' => null,
                            'error_message' => null,
                        ]);

                        match ($stage) {
                            'scenes' => GenerateScenesJob::dispatch($record->script_id),
                            'voiceover' => GenerateVoiceoverJob::dispatch($record->id),
                            'assets' => CollectVideoAssetsJob::dispatch($record->id),
                            'subtitles' => GenerateSubtitlesJob::dispatch($record->id),
                            'render' => RenderVideoJob::dispatch($record->id),
                            'quality_check' => QualityCheckVideoJob::dispatch($record->id),
                            default => null,
                        };

                        Notification::make()->title('Retry queued')->success()->send();
                    }),
                EditAction::make(),
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Filament/VideoRetryActionTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full suite**

Run: `php artisan test && vendor/bin/pint --test`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/Videos/Tables/VideosTable.php tests/Feature/Filament/VideoRetryActionTest.php
git commit -m "Show pipeline stage and add one-click retry to the videos table"
```

---

### Task 12: View page with per-stage artifact preview

**Files:**
- Create: `app/Filament/Resources/Videos/Pages/ViewVideo.php`
- Modify: `app/Filament/Resources/Videos/VideoResource.php`
- Modify: `app/Filament/Resources/Videos/Schemas/VideoForm.php`
- Modify: `app/Filament/Resources/Videos/Tables/VideosTable.php`
- Test: `tests/Feature/Filament/VideoViewPageTest.php` (new)

**Interfaces:**
- Consumes: `Video::script(): BelongsTo`, `Video::scenes(): HasMany`, `Video::voiceover(): HasOne`, `Video::subtitle(): BelongsTo` (all existing relations)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/VideoViewPageTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Videos\Pages\ViewVideo;
use App\Models\Script;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VideoViewPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_view_page_shows_the_script_content(): void
    {
        $this->actingAs(User::factory()->create());

        $script = Script::factory()->create(['content' => 'This is the narration text.']);
        $video = Video::factory()->create(['script_id' => $script->id]);

        Livewire::test(ViewVideo::class, ['record' => $video->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('This is the narration text.');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Filament/VideoViewPageTest.php`
Expected: FAIL — `ViewVideo` class does not exist / no `view` route registered.

- [ ] **Step 3: Add the View page**

Create `app/Filament/Resources/Videos/Pages/ViewVideo.php`:

```php
<?php

namespace App\Filament\Resources\Videos\Pages;

use App\Filament\Resources\Videos\VideoResource;
use Filament\Resources\Pages\ViewRecord;

class ViewVideo extends ViewRecord
{
    protected static string $resource = VideoResource::class;
}
```

In `app/Filament/Resources/Videos/VideoResource.php`, add the import:

```php
use App\Filament\Resources\Videos\Pages\ViewVideo;
```

and add to `getPages()`:

```php
    public static function getPages(): array
    {
        return [
            'index' => ListVideos::route('/'),
            'create' => CreateVideo::route('/create'),
            'edit' => EditVideo::route('/{record}/edit'),
            'view' => ViewVideo::route('/{record}'),
        ];
    }
```

In `app/Filament/Resources/Videos/Tables/VideosTable.php`, add the import `use Filament\Actions\ViewAction;` and add `ViewAction::make(),` right before `EditAction::make(),` in `recordActions`.

- [ ] **Step 4: Run the test to verify it still fails (no content yet)**

Run: `php artisan test tests/Feature/Filament/VideoViewPageTest.php`
Expected: FAIL — page now renders (route exists) but `'This is the narration text.'` is not shown anywhere.

- [ ] **Step 5: Add the artifact preview fields**

In `app/Filament/Resources/Videos/Schemas/VideoForm.php`, add imports:

```php
use App\Models\Video;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
```

Add these fields right before the existing `Textarea::make('error_message')` field at the end of `configure()`:

```php
                Textarea::make('script_preview')
                    ->label('Script')
                    ->dehydrated(false)
                    ->disabled()
                    ->columnSpanFull()
                    ->afterStateHydrated(function (Textarea $component, ?Video $record): void {
                        $component->state($record?->script?->content);
                    }),
                Textarea::make('scenes_preview')
                    ->label('Scenes')
                    ->dehydrated(false)
                    ->disabled()
                    ->columnSpanFull()
                    ->afterStateHydrated(function (Textarea $component, ?Video $record): void {
                        if ($record === null) {
                            return;
                        }

                        $lines = $record->scenes->map(
                            fn ($scene): string => "#{$scene->order} [{$scene->type->value}] {$scene->duration}s — {$scene->visual_query}"
                        );

                        $component->state($lines->implode("\n"));
                    }),
                Placeholder::make('voiceover_preview')
                    ->label('Voiceover')
                    ->content(function (?Video $record): HtmlString {
                        if ($record?->voiceover?->file_path === null) {
                            return new HtmlString('—');
                        }

                        $url = Storage::disk(config('filesystems.default'))->url($record->voiceover->file_path);

                        return new HtmlString("<a href=\"{$url}\" target=\"_blank\" rel=\"noopener\">Play voiceover</a>");
                    }),
                Textarea::make('subtitles_preview')
                    ->label('Subtitles')
                    ->dehydrated(false)
                    ->disabled()
                    ->columnSpanFull()
                    ->afterStateHydrated(function (Textarea $component, ?Video $record): void {
                        if ($record?->subtitle?->path === null) {
                            return;
                        }

                        $component->state(Storage::disk(config('filesystems.default'))->get($record->subtitle->path));
                    }),
                Placeholder::make('render_preview')
                    ->label('Final video')
                    ->content(function (?Video $record): HtmlString {
                        if ($record?->file_path === null) {
                            return new HtmlString('—');
                        }

                        $url = Storage::disk(config('filesystems.default'))->url($record->file_path);

                        return new HtmlString("<a href=\"{$url}\" target=\"_blank\" rel=\"noopener\">Open rendered video</a>");
                    }),
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Filament/VideoViewPageTest.php`
Expected: PASS.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test && vendor/bin/pint --test`
Expected: all green — this also confirms Create/Edit pages still work with the new synthetic, non-persisted fields (`dehydrated(false)` keeps them out of the save payload).

- [ ] **Step 8: Commit**

```bash
git add app/Filament/Resources/Videos/Pages/ViewVideo.php app/Filament/Resources/Videos/VideoResource.php app/Filament/Resources/Videos/Schemas/VideoForm.php app/Filament/Resources/Videos/Tables/VideosTable.php tests/Feature/Filament/VideoViewPageTest.php
git commit -m "Add a view page with per-stage artifact previews"
```

---

### Task 13: End-to-end chain integration test

**Files:**
- Create: `tests/Feature/Jobs/PipelineChainTest.php`

This is the only task with no corresponding "app" change — it proves Tasks 1–7 hand off to each other correctly as one continuous run, which the per-job tests (Tasks 1–7) don't individually prove (they only check that *a* dispatch happens, not that the whole sequence completes with consistent IDs end to end).

- [ ] **Step 1: Write the test**

Create `tests/Feature/Jobs/PipelineChainTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\AudioProbeInterface;
use App\Domain\Video\Providers\FakeAssetProvider;
use App\Domain\Video\Providers\FakeAudioProbe;
use App\Domain\Video\Providers\FakeTranscriptionProvider;
use App\Domain\Video\Providers\FakeTtsProvider;
use App\Domain\Video\Providers\FakeVideoQualityChecker;
use App\Domain\Video\Providers\FakeVideoRenderer;
use App\Domain\Video\QualityCheckResult;
use App\Domain\Video\RenderResult;
use App\Domain\Video\TranscriptionProviderInterface;
use App\Domain\Video\TtsProviderInterface;
use App\Domain\Video\VideoQualityCheckerInterface;
use App\Domain\Video\VideoRendererInterface;
use App\Jobs\CollectVideoAssetsJob;
use App\Jobs\GenerateScenesJob;
use App\Jobs\GenerateScriptJob;
use App\Jobs\GenerateSubtitlesJob;
use App\Jobs\GenerateVoiceoverJob;
use App\Jobs\QualityCheckVideoJob;
use App\Jobs\RenderVideoJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\Script;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PipelineChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_run_of_every_stage_dispatches_the_next_stage(): void
    {
        Storage::fake(config('filesystems.default'));

        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $project = ContentProject::factory()->create(['settings' => ['tts' => ['voice' => 'adam']]]);
        $idea = ContentIdea::factory()->create([
            'content_project_id' => $project->id,
            'status' => ContentIdeaStatus::Approved,
        ]);

        // Stage 1: script
        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(json_encode([
                'title' => 'T', 'hook' => 'H', 'script' => 'Body', 'estimated_duration' => 6, 'cta' => 'Follow',
            ]));
        });

        Queue::fake();
        app()->call([new GenerateScriptJob($idea->id), 'handle']);
        Queue::assertPushed(GenerateScenesJob::class);

        $script = Script::where('content_idea_id', $idea->id)->sole();

        // Stage 2: scenes
        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(json_encode([
                'scenes' => [
                    ['type' => 'hook', 'duration' => 3, 'visual_query' => 'a', 'text' => 'Hi'],
                    ['type' => 'cta', 'duration' => 3, 'visual_query' => 'b', 'text' => 'Bye'],
                ],
            ]));
        });

        Queue::fake();
        app()->call([new GenerateScenesJob($script->id), 'handle']);
        Queue::assertPushed(GenerateVoiceoverJob::class);

        $video = Video::where('script_id', $script->id)->sole();

        // Stage 3: voiceover
        $this->app->bind(TtsProviderInterface::class, function () {
            return (new FakeTtsProvider)->respondWith('audio-bytes', 'elevenlabs');
        });
        $this->app->bind(AudioProbeInterface::class, function () {
            return (new FakeAudioProbe)->respondWith(6.0);
        });

        Queue::fake();
        app()->call([new GenerateVoiceoverJob($video->id), 'handle']);
        Queue::assertPushed(CollectVideoAssetsJob::class);

        // Stage 4: assets
        $asset = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $this->app->bind(AssetProviderInterface::class, fn () => (new FakeAssetProvider)->respondWith([$asset]));

        Queue::fake();
        app()->call([new CollectVideoAssetsJob($video->id), 'handle']);
        Queue::assertPushed(GenerateSubtitlesJob::class);

        // Stage 5: subtitles
        $this->app->bind(TranscriptionProviderInterface::class, function () {
            return (new FakeTranscriptionProvider)->respondWith(
                [['start' => 0.0, 'end' => 6.0, 'text' => 'Hi Bye']],
                'en',
            );
        });

        Queue::fake();
        app()->call([new GenerateSubtitlesJob($video->id), 'handle']);
        Queue::assertPushed(RenderVideoJob::class);

        // Stage 6: render
        $this->app->bind(VideoRendererInterface::class, function () {
            return (new FakeVideoRenderer)->respondWith(
                new RenderResult(path: 'projects/1/renders/1.mp4', duration: 6.0, width: 1080, height: 1920)
            );
        });

        Queue::fake();
        app()->call([new RenderVideoJob($video->id), 'handle']);
        Queue::assertPushed(QualityCheckVideoJob::class);

        // Stage 7: quality check — end of chain
        $this->app->bind(VideoQualityCheckerInterface::class, function () {
            return (new FakeVideoQualityChecker)->respondWith(
                new QualityCheckResult(passed: true, checks: ['has_video_stream' => true], notes: [])
            );
        });

        Queue::fake();
        app()->call([new QualityCheckVideoJob($video->id), 'handle']);
        Queue::assertNothingPushed();

        $fresh = $video->fresh();
        $this->assertSame(VideoStatus::Rendered, $fresh->status);
        $this->assertTrue($fresh->quality_passed);
        $this->assertNull($fresh->failed_stage);
    }
}
```

- [ ] **Step 2: Run the test**

Run: `php artisan test tests/Feature/Jobs/PipelineChainTest.php`
Expected: PASS. If any stage fails, the error will point at exactly which hand-off is broken — check that stage's task above for a mismatched field name or missing fake binding.

- [ ] **Step 3: Run the entire project test suite and pint**

Run: `php artisan test && vendor/bin/pint --test`
Expected: all green.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Jobs/PipelineChainTest.php
git commit -m "Add end-to-end pipeline chain integration test"
```

---

## Manual verification (after all 13 tasks)

Not a coding task — a final human/agent sanity check before calling this done, using the real DeepSeek/ElevenLabs keys already configured in `.env`:

1. `php artisan queue:restart` (picks up all the new code in any running worker — see the earlier session's DeepSeek-provider incident for why this matters)
2. Start a worker: `php artisan queue:work --queue=render,whisper,default`
3. In Filament, open Content Projects → edit a channel → set "Publish platforms" and "Default voice" → save
4. Go to Videos → "Generate Video" → pick that channel, type a topic → submit
5. Watch the Videos table: the `stage` badge should progress through the labels from `currentStageLabel()` without touching anything else
6. Open the finished video's View page and confirm the script, scenes, voiceover link, subtitles, and rendered-video link all show real content
7. Deliberately break one stage (e.g. temporarily set an invalid `ELEVENLABS_API_KEY`), confirm the row turns "Failed: Voiceover" with an `error_message`, fix the key, click "Retry", confirm it finishes
