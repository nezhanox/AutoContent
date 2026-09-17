# Phase 3e — Rendering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Render a `Video` with ready scenes/voiceover/subtitles into a final `1080x1920.mp4` (burned-in styled subtitles, mixed voiceover + optional background music) via `VideoRendererInterface`/`FfmpegVideoRenderer`, and run a minimal technical quality check via `VideoQualityCheckerInterface`/`FfprobeVideoQualityChecker`.

**Architecture:** `FfmpegVideoRenderer` builds the final video through several sequential `ffmpeg` invocations (per-scene normalization → concat/xfade → ASS burn-in + audio mix), all via `Illuminate\Support\Facades\Process` (same pattern as `WhisperCliTranscriptionProvider`). `RenderVideoJob`/`QualityCheckVideoJob` run on a new dedicated `render` queue (same pattern as the existing `whisper` queue), because `ffmpeg`/`ffprobe` only exist in the `worker` container, not `horizon`.

**Tech Stack:** Laravel 12, PHP 8.4, `Illuminate\Support\Facades\Process` (ffmpeg/ffprobe CLI), Filament v4, PostgreSQL (jsonb), Redis queues, PHPUnit, `Process::fake()`.

**Spec:** `docs/superpowers/specs/2026-09-17-phase3e-rendering-design.md`

## Global Constraints

- One vertical template only: `1080x1920` (`config('render.resolution')`), no other resolutions/orientations.
- No LLM calls anywhere in this phase — quality check is `ffprobe`/`ffmpeg blackdetect` only.
- All ffmpeg/ffprobe process calls go through `Illuminate\Support\Facades\Process`, never raw `exec`/`shell_exec`/Symfony `Process` directly.
- `RenderVideoJob` and `QualityCheckVideoJob` both run `onQueue('render')`; `docker/worker/Dockerfile`'s `queue:work` must list `render` before `whisper,default`.
- No test in this plan may require a real `ffmpeg`/`ffprobe` binary — every process call is intercepted with `Process::fake()`.
- Per-call ffmpeg/ffprobe timeout (`config('render.timeout')` = 180s) is intentionally smaller than `RenderVideoJob.timeout` (900s) — never make them equal or invert them.
- Follow existing job conventions exactly: `ShouldBeUnique`, `Dispatchable, InteractsWithQueue, Queueable` traits, `uniqueId()` returns `(string) $this->videoId`, `backoff()` returns an array, `failed()` only logs via `Log::channel('video')` (no DB status change — this is a known accepted gap, do not "fix" it in this plan).

---

### Task 1: `videos` schema additions + `Video` model relation

**Files:**
- Create: `database/migrations/2026_09_17_120000_add_render_fields_to_videos_table.php`
- Modify: `app/Models/Video.php`
- Test: `tests/Feature/Domain/Video/VideoRenderFieldsTest.php`

**Interfaces:**
- Produces: `Video::musicAsset(): BelongsTo` (→ `MediaAsset`), `Video.music_asset_id` (nullable FK), `Video.quality_passed` (nullable bool, cast `boolean`), `Video.quality_report` (nullable array, cast `array`). All later tasks that update `Video` rows (Task 9, Task 10) rely on these three columns being fillable and cast.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Domain\Video;

use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoRenderFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_video_can_have_a_music_asset(): void
    {
        $music = MediaAsset::factory()->create(['type' => MediaAssetType::Audio]);
        $video = Video::factory()->create(['music_asset_id' => $music->id]);

        $this->assertTrue($video->musicAsset->is($music));
    }

    public function test_music_asset_id_is_nullable(): void
    {
        $video = Video::factory()->create(['music_asset_id' => null]);

        $this->assertNull($video->fresh()->musicAsset);
    }

    public function test_quality_passed_and_quality_report_are_cast(): void
    {
        $video = Video::factory()->create([
            'quality_passed' => true,
            'quality_report' => ['checks' => ['has_video_stream' => true]],
        ]);

        $fresh = $video->fresh();

        $this->assertTrue($fresh->quality_passed);
        $this->assertSame(['checks' => ['has_video_stream' => true]], $fresh->quality_report);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VideoRenderFieldsTest`
Expected: FAIL — `Undefined property: App\Models\Video::$musicAsset` / `SQLSTATE... column "music_asset_id" does not exist` (migration and model changes don't exist yet).

- [ ] **Step 3: Create the migration**

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
            $table->foreignId('music_asset_id')->nullable()->after('subtitle_id')
                ->constrained('media_assets')->nullOnDelete();
            $table->boolean('quality_passed')->nullable()->after('metadata');
            $table->jsonb('quality_report')->nullable()->after('quality_passed');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropForeign(['music_asset_id']);
            $table->dropColumn(['music_asset_id', 'quality_passed', 'quality_report']);
        });
    }
};
```

- [ ] **Step 4: Update `app/Models/Video.php`**

Replace the `$fillable` array and `casts()` method, and add the new relation:

```php
    protected $fillable = [
        'content_project_id', 'content_idea_id', 'script_id', 'title', 'description',
        'status', 'duration', 'width', 'height', 'file_path', 'thumbnail_path',
        'subtitle_id', 'music_asset_id', 'quality_passed', 'quality_report',
        'metadata', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => VideoStatus::class,
            'metadata' => 'array',
            'quality_passed' => 'boolean',
            'quality_report' => 'array',
        ];
    }
```

Add after `subtitle(): BelongsTo`:

```php
    public function musicAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'music_asset_id');
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=VideoRenderFieldsTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Run the full suite and Pint to check for regressions**

Run: `php artisan test && vendor/bin/pint --test`
Expected: all green, no style violations.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_17_120000_add_render_fields_to_videos_table.php app/Models/Video.php tests/Feature/Domain/Video/VideoRenderFieldsTest.php
git commit -m "Add music_asset_id, quality_passed, quality_report to videos"
```

---

### Task 2: Domain interfaces and DTOs

**Files:**
- Create: `app/Domain/Video/VideoRendererInterface.php`
- Create: `app/Domain/Video/RenderResult.php`
- Create: `app/Domain/Video/VideoQualityCheckerInterface.php`
- Create: `app/Domain/Video/QualityCheckResult.php`

**Interfaces:**
- Consumes: nothing (leaf files).
- Produces: `VideoRendererInterface::render(Video $video): RenderResult`; `RenderResult` with public readonly `path: string, duration: float, width: int, height: int, metadata: array`; `VideoQualityCheckerInterface::check(Video $video): QualityCheckResult`; `QualityCheckResult` with public readonly `passed: bool, checks: array, notes: array, metadata: array`. Tasks 5, 6, 7, 8, 9, 10 depend on these exact names/signatures.

There is no dedicated automated test for these files — they are plain interfaces/DTOs with no behavior (matching the existing codebase convention: `VoiceResult`, `TranscriptionResult`, `VoiceSettings` have no dedicated test files either). They are exercised through the tests in Tasks 6, 7, 9, 10.

- [ ] **Step 1: Create `app/Domain/Video/VideoRendererInterface.php`**

```php
<?php

namespace App\Domain\Video;

use App\Models\Video;

interface VideoRendererInterface
{
    public function render(Video $video): RenderResult;
}
```

- [ ] **Step 2: Create `app/Domain/Video/RenderResult.php`**

```php
<?php

namespace App\Domain\Video;

final class RenderResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $path,
        public readonly float $duration,
        public readonly int $width,
        public readonly int $height,
        public readonly array $metadata = [],
    ) {}
}
```

- [ ] **Step 3: Create `app/Domain/Video/VideoQualityCheckerInterface.php`**

```php
<?php

namespace App\Domain\Video;

use App\Models\Video;

interface VideoQualityCheckerInterface
{
    public function check(Video $video): QualityCheckResult;
}
```

- [ ] **Step 4: Create `app/Domain/Video/QualityCheckResult.php`**

```php
<?php

namespace App\Domain\Video;

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

- [ ] **Step 5: Verify syntax and style**

Run: `php -l app/Domain/Video/VideoRendererInterface.php app/Domain/Video/RenderResult.php app/Domain/Video/VideoQualityCheckerInterface.php app/Domain/Video/QualityCheckResult.php && vendor/bin/pint --test app/Domain/Video`
Expected: `No syntax errors detected` ×4, Pint reports no style issues.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Video/VideoRendererInterface.php app/Domain/Video/RenderResult.php app/Domain/Video/VideoQualityCheckerInterface.php app/Domain/Video/QualityCheckResult.php
git commit -m "Add VideoRendererInterface and VideoQualityCheckerInterface with their DTOs"
```

---

### Task 3: `config/render.php`, `.env.example`, and the `render` queue in Docker

**Files:**
- Create: `config/render.php`
- Modify: `.env.example`
- Modify: `docker/worker/Dockerfile`

**Interfaces:**
- Produces: `config('render.*')` keys consumed by Task 4 (`subtitles`, `resolution`), Task 6 (`ffmpeg_binary`, `timeout`, `resolution`, `fps`, `transition`, `audio`, `subtitles`), Task 7 (`ffprobe_binary`, `ffmpeg_binary`, `timeout`, `resolution`, `quality_check.duration_tolerance`).

No automated test for this task (matches the existing `config/whisper.php` precedent — no dedicated config test exists in this codebase). Verification is via `php artisan config:show` and a Docker build.

- [ ] **Step 1: Create `config/render.php`**

```php
<?php

return [
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),

    // Timeout for a SINGLE ffmpeg/ffprobe invocation (render() makes several
    // per render). Intentionally much smaller than RenderVideoJob::$timeout
    // (900s) — a single hung call must never be able to exhaust the job's
    // whole budget by itself.
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

- [ ] **Step 2: Add new env vars to `.env.example` and bump `REDIS_QUEUE_RETRY_AFTER`**

Find the line `REDIS_QUEUE_RETRY_AFTER=700` (right after the `WHISPER_*` block) and replace that whole block with:

```
WHISPER_PYTHON_BINARY=python3
WHISPER_MODEL=base
WHISPER_TIMEOUT=600

FFMPEG_BINARY=ffmpeg
FFPROBE_BINARY=ffprobe
RENDER_TIMEOUT=180
RENDER_WIDTH=1080
RENDER_HEIGHT=1920
RENDER_FPS=30
RENDER_SUBTITLE_FONT="DejaVu Sans"
RENDER_SUBTITLE_FONT_SIZE=64
RENDER_SUBTITLE_POSITION=bottom
RENDER_SUBTITLE_MARGIN_V=120
RENDER_SUBTITLE_MARGIN_H=60
RENDER_SUBTITLE_COLOR="&H00FFFFFF"
RENDER_SUBTITLE_OUTLINE_COLOR="&H00000000"
RENDER_VOICE_VOLUME=1.0
RENDER_MUSIC_VOLUME=0.15
RENDER_TRANSITION_TYPE=fade
RENDER_TRANSITION_DURATION=0.5
RENDER_QUALITY_DURATION_TOLERANCE=2.0

REDIS_QUEUE_RETRY_AFTER=950
```

(`REDIS_QUEUE_RETRY_AFTER` must stay above `RenderVideoJob::$timeout` = 900, set in Task 9.)

- [ ] **Step 3: Update `docker/worker/Dockerfile` CMD**

Change the last line from:

```dockerfile
CMD ["php", "artisan", "queue:work", "--queue=whisper,default", "--sleep=3", "--tries=3"]
```

to:

```dockerfile
CMD ["php", "artisan", "queue:work", "--queue=render,whisper,default", "--sleep=3", "--tries=3"]
```

- [ ] **Step 4: Verify config loads and Docker builds**

Run: `php artisan config:show render`
Expected: prints the full `render` config array with the defaults above, no errors.

Run: `docker compose build worker`
Expected: image builds successfully (no new packages added, only the CMD line changed, so this should be fast).

- [ ] **Step 5: Commit**

```bash
git add config/render.php .env.example docker/worker/Dockerfile
git commit -m "Add render config, render queue, and bump REDIS_QUEUE_RETRY_AFTER"
```

---

### Task 4: `AssSubtitleFormatter`

**Files:**
- Create: `app/Domain/Video/Support/AssSubtitleFormatter.php`
- Test: `tests/Unit/Domain/Video/AssSubtitleFormatterTest.php`

**Interfaces:**
- Consumes: nothing (pure function, takes plain arrays).
- Produces: `AssSubtitleFormatter::format(array $segments, array $style): string`. `$style` must contain keys `width`, `height`, `font`, `font_size`, `position`, `margin_v`, `margin_h`, `primary_colour`, `outline_colour` (Task 6 builds this array by merging `config('render.subtitles')` with `config('render.resolution')`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Support\AssSubtitleFormatter;
use Tests\TestCase;

class AssSubtitleFormatterTest extends TestCase
{
    private function style(array $overrides = []): array
    {
        return array_merge([
            'width' => 1080,
            'height' => 1920,
            'font' => 'DejaVu Sans',
            'font_size' => 64,
            'position' => 'bottom',
            'margin_v' => 120,
            'margin_h' => 60,
            'primary_colour' => '&H00FFFFFF',
            'outline_colour' => '&H00000000',
        ], $overrides);
    }

    public function test_it_writes_the_script_info_and_style_header(): void
    {
        $ass = AssSubtitleFormatter::format([], $this->style());

        $this->assertStringContainsString('[Script Info]', $ass);
        $this->assertStringContainsString('PlayResX: 1080', $ass);
        $this->assertStringContainsString('PlayResY: 1920', $ass);
        $this->assertStringContainsString(
            'Style: Default,DejaVu Sans,64,&H00FFFFFF,&H00000000,2,60,60,120',
            $ass
        );
    }

    public function test_bottom_position_maps_to_alignment_two(): void
    {
        $ass = AssSubtitleFormatter::format([], $this->style(['position' => 'bottom']));
        $this->assertStringContainsString(',2,60,60,120', $ass);
    }

    public function test_top_position_maps_to_alignment_eight(): void
    {
        $ass = AssSubtitleFormatter::format([], $this->style(['position' => 'top']));
        $this->assertStringContainsString(',8,60,60,120', $ass);
    }

    public function test_middle_position_maps_to_alignment_five(): void
    {
        $ass = AssSubtitleFormatter::format([], $this->style(['position' => 'middle']));
        $this->assertStringContainsString(',5,60,60,120', $ass);
    }

    public function test_empty_segments_produce_a_valid_header_with_no_dialogue_lines(): void
    {
        $ass = AssSubtitleFormatter::format([], $this->style());

        $this->assertStringContainsString('[Events]', $ass);
        $this->assertStringNotContainsString('Dialogue:', $ass);
    }

    public function test_it_writes_a_dialogue_line_per_segment_with_ass_timestamps(): void
    {
        $ass = AssSubtitleFormatter::format([
            ['start' => 0.0, 'end' => 2.4, 'text' => 'Hello world'],
        ], $this->style());

        $this->assertStringContainsString('Dialogue: 0,0:00:00.00,0:00:02.40,Default,Hello world', $ass);
    }

    public function test_it_escapes_newlines_as_ass_hard_line_breaks(): void
    {
        $ass = AssSubtitleFormatter::format([
            ['start' => 0.0, 'end' => 1.0, 'text' => "Line one\nLine two"],
        ], $this->style());

        $this->assertStringContainsString('Line one\\NLine two', $ass);
    }

    public function test_it_rolls_over_minutes_and_hours_correctly(): void
    {
        $ass = AssSubtitleFormatter::format([
            ['start' => 3661.5, 'end' => 3662.0, 'text' => 'One hour in'],
        ], $this->style());

        $this->assertStringContainsString('Dialogue: 0,1:01:01.50,1:01:02.00,Default,One hour in', $ass);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AssSubtitleFormatterTest`
Expected: FAIL — `Class "App\Domain\Video\Support\AssSubtitleFormatter" not found`.

- [ ] **Step 3: Implement `AssSubtitleFormatter`**

```php
<?php

namespace App\Domain\Video\Support;

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

        return sprintf(
            '%d:%02d:%02d.%02d',
            intdiv($whole, 3600),
            intdiv($whole % 3600, 60),
            $whole % 60,
            (int) round(($seconds - $whole) * 100),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AssSubtitleFormatterTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Video/Support/AssSubtitleFormatter.php tests/Unit/Domain/Video/AssSubtitleFormatterTest.php
git commit -m "Add AssSubtitleFormatter for styled ASS subtitle generation"
```

---

### Task 5: `FakeVideoRenderer` and `FakeVideoQualityChecker` test doubles

**Files:**
- Create: `app/Domain/Video/Providers/FakeVideoRenderer.php`
- Create: `app/Domain/Video/Providers/FakeVideoQualityChecker.php`

**Interfaces:**
- Consumes: `VideoRendererInterface`, `RenderResult`, `VideoQualityCheckerInterface`, `QualityCheckResult` (Task 2).
- Produces: `FakeVideoRenderer::respondWith(RenderResult $result): static`, `FakeVideoRenderer::render(Video $video): RenderResult`; `FakeVideoQualityChecker::respondWith(QualityCheckResult $result): static`, `FakeVideoQualityChecker::check(Video $video): QualityCheckResult`. Tasks 9 and 10 bind these in the container to test the jobs without real ffmpeg.

No dedicated test — these are test infrastructure themselves (matching `FakeTranscriptionProvider`, which also has no test file). They're exercised by Tasks 9 and 10.

- [ ] **Step 1: Create `app/Domain/Video/Providers/FakeVideoRenderer.php`**

```php
<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\RenderResult;
use App\Domain\Video\VideoRendererInterface;
use App\Models\Video;

final class FakeVideoRenderer implements VideoRendererInterface
{
    private ?RenderResult $result = null;

    public function respondWith(RenderResult $result): static
    {
        $this->result = $result;

        return $this;
    }

    public function render(Video $video): RenderResult
    {
        return $this->result ?? new RenderResult(
            path: "projects/{$video->content_project_id}/renders/{$video->id}.mp4",
            duration: (float) $video->scenes->sum('duration'),
            width: 1080,
            height: 1920,
        );
    }
}
```

- [ ] **Step 2: Create `app/Domain/Video/Providers/FakeVideoQualityChecker.php`**

```php
<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\QualityCheckResult;
use App\Domain\Video\VideoQualityCheckerInterface;
use App\Models\Video;

final class FakeVideoQualityChecker implements VideoQualityCheckerInterface
{
    private ?QualityCheckResult $result = null;

    public function respondWith(QualityCheckResult $result): static
    {
        $this->result = $result;

        return $this;
    }

    public function check(Video $video): QualityCheckResult
    {
        return $this->result ?? new QualityCheckResult(passed: true, checks: ['has_video_stream' => true]);
    }
}
```

- [ ] **Step 3: Verify syntax and style**

Run: `php -l app/Domain/Video/Providers/FakeVideoRenderer.php app/Domain/Video/Providers/FakeVideoQualityChecker.php && vendor/bin/pint --test app/Domain/Video/Providers`
Expected: `No syntax errors detected` ×2, no style issues.

- [ ] **Step 4: Commit**

```bash
git add app/Domain/Video/Providers/FakeVideoRenderer.php app/Domain/Video/Providers/FakeVideoQualityChecker.php
git commit -m "Add FakeVideoRenderer and FakeVideoQualityChecker test doubles"
```

---

### Task 6: `FfmpegVideoRenderer`

**Files:**
- Create: `app/Domain/Video/Providers/FfmpegVideoRenderer.php`
- Test: `tests/Unit/Domain/Video/FfmpegVideoRendererTest.php`

**Interfaces:**
- Consumes: `VideoRendererInterface`, `RenderResult` (Task 2), `AssSubtitleFormatter` (Task 4), `config('render.*')` (Task 3), `Video::scenes/voiceover/subtitle/musicAsset` relations.
- Produces: `FfmpegVideoRenderer::render(Video $video): RenderResult` — the real implementation bound in Task 8 and used by `RenderVideoJob` (Task 9).

This is the most complex file in the plan. Steps below build it incrementally but land as one class + one test file, since the pieces only make sense together (the "smallest independently reviewable unit" here is the whole renderer, not each private method).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Providers\FfmpegVideoRenderer;
use App\Models\ContentProject;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use App\Models\Video;
use App\Models\VideoScene;
use App\Models\Voiceover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class FfmpegVideoRendererTest extends TestCase
{
    use RefreshDatabase;

    private function fakeFfmpegProcesses(): void
    {
        Process::fake(function ($process) {
            $command = $process->command;

            if (in_array('-show_streams', $command, true)) {
                return Process::result(output: json_encode([
                    'format' => ['duration' => '9.50'],
                    'streams' => [
                        ['codec_type' => 'video', 'width' => 1080, 'height' => 1920],
                        ['codec_type' => 'audio'],
                    ],
                ]));
            }

            $output = end($command);
            if (is_string($output) && str_ends_with($output, '.mp4')) {
                file_put_contents($output, 'fake-video-bytes');
            }

            return Process::result(output: '');
        });
    }

    private function buildVideo(bool $withMusic = false): Video
    {
        $project = ContentProject::factory()->create();
        $video = Video::factory()->create(['content_project_id' => $project->id]);

        $sceneAsset1 = MediaAsset::factory()->create(['type' => MediaAssetType::Image, 'path' => 'assets/scene1.jpg']);
        $sceneAsset2 = MediaAsset::factory()->create(['type' => MediaAssetType::Video, 'path' => 'assets/scene2.mp4']);

        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 0, 'duration' => 3, 'asset_id' => $sceneAsset1->id]);
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 1, 'duration' => 4, 'asset_id' => $sceneAsset2->id]);

        Voiceover::factory()->create([
            'video_id' => $video->id,
            'file_path' => "projects/{$project->id}/audio/{$video->id}.mp3",
        ]);

        $subtitle = MediaAsset::factory()->create([
            'type' => MediaAssetType::Subtitle,
            'metadata' => ['segments' => [['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']], 'language' => 'en'],
        ]);
        $video->update(['subtitle_id' => $subtitle->id]);

        if ($withMusic) {
            $music = MediaAsset::factory()->create(['type' => MediaAssetType::Audio, 'path' => 'assets/music.mp3']);
            $video->update(['music_asset_id' => $music->id]);
            Storage::disk(config('filesystems.default'))->put('assets/music.mp3', 'fake-music-bytes');
        }

        Storage::disk(config('filesystems.default'))->put('assets/scene1.jpg', 'fake-image-bytes');
        Storage::disk(config('filesystems.default'))->put('assets/scene2.mp4', 'fake-video-bytes');
        Storage::disk(config('filesystems.default'))->put("projects/{$project->id}/audio/{$video->id}.mp3", 'fake-audio-bytes');

        return $video->fresh(['scenes.asset', 'voiceover', 'subtitle', 'musicAsset']);
    }

    public function test_it_renders_and_returns_probed_dimensions_and_duration(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeFfmpegProcesses();

        $video = $this->buildVideo();
        $renderer = new FfmpegVideoRenderer;

        $result = $renderer->render($video);

        $this->assertSame(9.5, $result->duration);
        $this->assertSame(1080, $result->width);
        $this->assertSame(1920, $result->height);
        $this->assertSame("projects/{$video->content_project_id}/renders/{$video->id}.mp4", $result->path);
    }

    public function test_it_stores_the_rendered_file_on_the_configured_disk(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeFfmpegProcesses();

        $video = $this->buildVideo();
        (new FfmpegVideoRenderer)->render($video);

        Storage::disk(config('filesystems.default'))
            ->assertExists("projects/{$video->content_project_id}/renders/{$video->id}.mp4");
    }

    public function test_it_does_not_add_a_music_input_when_no_music_asset_is_set(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeFfmpegProcesses();

        $video = $this->buildVideo(withMusic: false);
        (new FfmpegVideoRenderer)->render($video);

        // 'music.mp3' can only ever appear in a command via mixAndBurn's music
        // input — asserting no recorded process contains it at all is a precise
        // (not just "some call happens to lack it") check on that one call site.
        Process::assertDidntRun(function ($process) {
            return str_contains(implode(' ', $process->command), 'music.mp3');
        });
    }

    public function test_it_adds_a_music_input_and_amix_filter_when_a_music_asset_is_set(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeFfmpegProcesses();

        $video = $this->buildVideo(withMusic: true);
        (new FfmpegVideoRenderer)->render($video);

        Process::assertRan(function ($process) {
            $joined = implode(' ', $process->command);

            return str_contains($joined, 'music.mp3') && str_contains($joined, 'amix');
        });
    }

    public function test_it_throws_when_scene_normalization_fails(): void
    {
        Storage::fake(config('filesystems.default'));
        Process::fake(['*' => Process::result(errorOutput: 'no such filter', exitCode: 1)]);

        $video = $this->buildVideo();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Scene 0 normalization failed');

        (new FfmpegVideoRenderer)->render($video);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FfmpegVideoRendererTest`
Expected: FAIL — `Class "App\Domain\Video\Providers\FfmpegVideoRenderer" not found`.

- [ ] **Step 3: Implement `FfmpegVideoRenderer`**

```php
<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\RenderResult;
use App\Domain\Video\Support\AssSubtitleFormatter;
use App\Domain\Video\VideoRendererInterface;
use App\Models\Enums\MediaAssetType;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class FfmpegVideoRenderer implements VideoRendererInterface
{
    public function render(Video $video): RenderResult
    {
        $disk = Storage::disk(config('filesystems.default'));
        $workDir = sys_get_temp_dir().'/render_'.$video->id.'_'.uniqid();
        File::makeDirectory($workDir, recursive: true);

        try {
            $sceneClips = [];
            $durations = [];

            foreach ($video->scenes as $index => $scene) {
                $sceneClips[] = $this->normalizeScene($scene, $disk, $workDir, $index);
                $durations[] = (float) $scene->duration;
            }

            $totalDuration = array_sum($durations);
            $concatPath = $this->concatenateClips($sceneClips, $durations, $workDir);

            $assPath = "{$workDir}/subtitles.ass";
            File::put($assPath, AssSubtitleFormatter::format(
                $video->subtitle->metadata['segments'] ?? [],
                [
                    ...config('render.subtitles'),
                    'width' => config('render.resolution.width'),
                    'height' => config('render.resolution.height'),
                ],
            ));

            $voicePath = $this->materialize($disk, $video->voiceover->file_path, $workDir, 'voice.mp3');
            $musicPath = $video->musicAsset !== null
                ? $this->materialize($disk, $video->musicAsset->path, $workDir, 'music.mp3')
                : null;

            $outputPath = "{$workDir}/output.mp4";
            $this->mixAndBurn($concatPath, $assPath, $voicePath, $musicPath, $totalDuration, $outputPath);

            $probe = $this->probe($outputPath);

            $storagePath = "projects/{$video->content_project_id}/renders/{$video->id}.mp4";
            $disk->put($storagePath, file_get_contents($outputPath));

            return new RenderResult(
                path: $storagePath,
                duration: $probe['duration'],
                width: $probe['width'],
                height: $probe['height'],
                metadata: ['transition' => config('render.transition.type')],
            );
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    private function normalizeScene(VideoScene $scene, Filesystem $disk, string $workDir, int $index): string
    {
        $asset = $scene->asset;
        $source = $this->materialize($disk, $asset->path, $workDir, "scene_{$index}_src".$this->extension($asset->path));
        $output = "{$workDir}/scene_{$index}.mp4";

        $width = config('render.resolution.width');
        $height = config('render.resolution.height');
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

    /**
     * @param  array<int, string>  $clips
     * @param  array<int, float>  $durations
     */
    private function concatenateClips(array $clips, array $durations, string $workDir): string
    {
        $output = "{$workDir}/concat.mp4";

        if (config('render.transition.type') === 'none' || count($clips) < 2) {
            return $this->concatenateWithoutTransition($clips, $workDir, $output);
        }

        $inputs = [];
        foreach ($clips as $clip) {
            $inputs[] = '-i';
            $inputs[] = $clip;
        }

        $transitionType = config('render.transition.type');
        $transitionDuration = (float) config('render.transition.duration');

        $filters = [];
        $cumulative = $durations[0];
        $currentLabel = '0';

        for ($i = 1; $i < count($clips); $i++) {
            $offset = $cumulative - ($i * $transitionDuration);
            $nextLabel = "v{$i}";
            $filters[] = "[{$currentLabel}][{$i}]xfade=transition={$transitionType}:duration={$transitionDuration}:offset={$offset}[{$nextLabel}]";
            $cumulative += $durations[$i];
            $currentLabel = $nextLabel;
        }

        $command = [
            $this->binary(), '-y', ...$inputs,
            '-filter_complex', implode(';', $filters),
            '-map', "[{$currentLabel}]",
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
            $output,
        ];

        $result = Process::timeout(config('render.timeout'))->run($command);

        if ($result->failed()) {
            throw new RuntimeException('Scene concatenation failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        return $output;
    }

    /**
     * @param  array<int, string>  $clips
     */
    private function concatenateWithoutTransition(array $clips, string $workDir, string $output): string
    {
        $listPath = "{$workDir}/concat_list.txt";
        File::put($listPath, implode("\n", array_map(
            static fn (string $clip): string => "file '{$clip}'",
            $clips,
        )));

        $result = Process::timeout(config('render.timeout'))->run([
            $this->binary(), '-y', '-f', 'concat', '-safe', '0', '-i', $listPath, '-c', 'copy', $output,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Scene concatenation failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        return $output;
    }

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

    /**
     * @return array{duration: float, width: int, height: int}
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

        return [
            'duration' => (float) ($decoded['format']['duration'] ?? 0.0),
            'width' => (int) ($videoStream['width'] ?? 0),
            'height' => (int) ($videoStream['height'] ?? 0),
        ];
    }

    private function materialize(Filesystem $disk, string $path, string $workDir, string $filename): string
    {
        $destination = "{$workDir}/{$filename}";
        File::put($destination, $disk->get($path));

        return $destination;
    }

    private function extension(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension !== '' ? ".{$extension}" : '';
    }

    private function escapeForFilter(string $path): string
    {
        return str_replace([':', '\\'], ['\\:', '\\\\'], $path);
    }

    private function binary(): string
    {
        return config('render.ffmpeg_binary');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=FfmpegVideoRendererTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --test app/Domain/Video/Providers/FfmpegVideoRenderer.php tests/Unit/Domain/Video/FfmpegVideoRendererTest.php`
Expected: no style issues.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Video/Providers/FfmpegVideoRenderer.php tests/Unit/Domain/Video/FfmpegVideoRendererTest.php
git commit -m "Add FfmpegVideoRenderer: multi-step ffmpeg render pipeline"
```

---

### Task 7: `FfprobeVideoQualityChecker`

**Files:**
- Create: `app/Domain/Video/Providers/FfprobeVideoQualityChecker.php`
- Test: `tests/Unit/Domain/Video/FfprobeVideoQualityCheckerTest.php`

**Interfaces:**
- Consumes: `VideoQualityCheckerInterface`, `QualityCheckResult` (Task 2), `config('render.*')` (Task 3).
- Produces: `FfprobeVideoQualityChecker::check(Video $video): QualityCheckResult` — bound in Task 8, used by `QualityCheckVideoJob` (Task 10).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Providers\FfprobeVideoQualityChecker;
use App\Models\ContentProject;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FfprobeVideoQualityCheckerTest extends TestCase
{
    use RefreshDatabase;

    private function buildRenderedVideo(): Video
    {
        $project = ContentProject::factory()->create();
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'file_path' => "projects/{$project->id}/renders/video.mp4",
        ]);
        VideoScene::factory()->create(['video_id' => $video->id, 'duration' => 5]);
        VideoScene::factory()->create(['video_id' => $video->id, 'duration' => 4]);

        Storage::disk(config('filesystems.default'))->put($video->file_path, 'fake-rendered-bytes');

        return $video->fresh('scenes');
    }

    private function fakeProbeAndBlackdetect(array $probeOverrides = [], string $blackdetectErrorOutput = ''): void
    {
        $probe = array_merge([
            'format' => ['duration' => '9.20'],
            'streams' => [
                ['codec_type' => 'video', 'width' => 1080, 'height' => 1920],
                ['codec_type' => 'audio'],
            ],
        ], $probeOverrides);

        Process::fake(function ($process) use ($probe, $blackdetectErrorOutput) {
            if (in_array('-show_streams', $process->command, true)) {
                return Process::result(output: json_encode($probe));
            }

            return Process::result(errorOutput: $blackdetectErrorOutput);
        });
    }

    public function test_it_passes_when_all_checks_succeed(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeProbeAndBlackdetect();

        $video = $this->buildRenderedVideo();
        $result = (new FfprobeVideoQualityChecker)->check($video);

        $this->assertTrue($result->passed);
        $this->assertTrue($result->checks['has_video_stream']);
        $this->assertTrue($result->checks['has_audio_stream']);
        $this->assertTrue($result->checks['resolution_matches']);
        $this->assertTrue($result->checks['duration_within_tolerance']);
        $this->assertTrue($result->checks['not_excessively_black']);
    }

    public function test_it_fails_resolution_check_when_dimensions_do_not_match(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeProbeAndBlackdetect(['streams' => [
            ['codec_type' => 'video', 'width' => 640, 'height' => 480],
            ['codec_type' => 'audio'],
        ]]);

        $video = $this->buildRenderedVideo();
        $result = (new FfprobeVideoQualityChecker)->check($video);

        $this->assertFalse($result->passed);
        $this->assertFalse($result->checks['resolution_matches']);
    }

    public function test_it_fails_audio_check_when_there_is_no_audio_stream(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeProbeAndBlackdetect(['streams' => [
            ['codec_type' => 'video', 'width' => 1080, 'height' => 1920],
        ]]);

        $video = $this->buildRenderedVideo();
        $result = (new FfprobeVideoQualityChecker)->check($video);

        $this->assertFalse($result->passed);
        $this->assertFalse($result->checks['has_audio_stream']);
    }

    public function test_it_fails_duration_check_when_outside_tolerance(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeProbeAndBlackdetect(['format' => ['duration' => '30.0']]);

        $video = $this->buildRenderedVideo();
        $result = (new FfprobeVideoQualityChecker)->check($video);

        $this->assertFalse($result->passed);
        $this->assertFalse($result->checks['duration_within_tolerance']);
    }

    public function test_it_fails_black_frame_check_when_blackdetect_reports_black_start(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeProbeAndBlackdetect(blackdetectErrorOutput: '[blackdetect @ 0x0] black_start:0 black_end:5 black_duration:5');

        $video = $this->buildRenderedVideo();
        $result = (new FfprobeVideoQualityChecker)->check($video);

        $this->assertFalse($result->passed);
        $this->assertFalse($result->checks['not_excessively_black']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FfprobeVideoQualityCheckerTest`
Expected: FAIL — `Class "App\Domain\Video\Providers\FfprobeVideoQualityChecker" not found`.

- [ ] **Step 3: Implement `FfprobeVideoQualityChecker`**

```php
<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\QualityCheckResult;
use App\Domain\Video\VideoQualityCheckerInterface;
use App\Models\Video;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

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

    /**
     * @return array{duration: float, width: int, height: int, has_video: bool, has_audio: bool}
     */
    private function probe(string $path): array
    {
        $result = Process::timeout(config('render.timeout'))->run([
            config('render.ffprobe_binary'), '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $path,
        ]);

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

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=FfprobeVideoQualityCheckerTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --test app/Domain/Video/Providers/FfprobeVideoQualityChecker.php tests/Unit/Domain/Video/FfprobeVideoQualityCheckerTest.php`
Expected: no style issues.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Video/Providers/FfprobeVideoQualityChecker.php tests/Unit/Domain/Video/FfprobeVideoQualityCheckerTest.php
git commit -m "Add FfprobeVideoQualityChecker: technical quality checks via ffprobe"
```

---

### Task 8: `RenderServiceProvider`

**Files:**
- Create: `app/Providers/RenderServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Test: `tests/Feature/Domain/Video/RenderServiceProviderTest.php`

**Interfaces:**
- Consumes: `VideoRendererInterface`/`FfmpegVideoRenderer`, `VideoQualityCheckerInterface`/`FfprobeVideoQualityChecker` (Tasks 2, 6, 7).
- Produces: the container now resolves `VideoRendererInterface` to `FfmpegVideoRenderer` and `VideoQualityCheckerInterface` to `FfprobeVideoQualityChecker` by default — Tasks 9 and 10's jobs rely on this for production use (tests override the bindings with the Fakes from Task 5).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Domain\Video;

use App\Domain\Video\Providers\FfmpegVideoRenderer;
use App\Domain\Video\Providers\FfprobeVideoQualityChecker;
use App\Domain\Video\VideoQualityCheckerInterface;
use App\Domain\Video\VideoRendererInterface;
use Tests\TestCase;

class RenderServiceProviderTest extends TestCase
{
    public function test_video_renderer_interface_resolves_to_ffmpeg_renderer(): void
    {
        $this->assertInstanceOf(FfmpegVideoRenderer::class, $this->app->make(VideoRendererInterface::class));
    }

    public function test_video_quality_checker_interface_resolves_to_ffprobe_checker(): void
    {
        $this->assertInstanceOf(FfprobeVideoQualityChecker::class, $this->app->make(VideoQualityCheckerInterface::class));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RenderServiceProviderTest`
Expected: FAIL — `Target [App\Domain\Video\VideoRendererInterface] is not instantiable`.

- [ ] **Step 3: Create `app/Providers/RenderServiceProvider.php`**

```php
<?php

namespace App\Providers;

use App\Domain\Video\Providers\FfmpegVideoRenderer;
use App\Domain\Video\Providers\FfprobeVideoQualityChecker;
use App\Domain\Video\VideoQualityCheckerInterface;
use App\Domain\Video\VideoRendererInterface;
use Illuminate\Support\ServiceProvider;

class RenderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VideoRendererInterface::class, FfmpegVideoRenderer::class);
        $this->app->bind(VideoQualityCheckerInterface::class, FfprobeVideoQualityChecker::class);
    }
}
```

- [ ] **Step 4: Register it in `bootstrap/providers.php`**

```php
<?php

use App\Providers\AppServiceProvider;
use App\Providers\AssetServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\LlmServiceProvider;
use App\Providers\RenderServiceProvider;
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
    RenderServiceProvider::class,
];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=RenderServiceProviderTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Providers/RenderServiceProvider.php bootstrap/providers.php tests/Feature/Domain/Video/RenderServiceProviderTest.php
git commit -m "Bind VideoRendererInterface and VideoQualityCheckerInterface"
```

---

### Task 9: `RenderVideoJob`

**Files:**
- Create: `app/Jobs/RenderVideoJob.php`
- Test: `tests/Feature/Jobs/RenderVideoJobTest.php`

**Interfaces:**
- Consumes: `VideoRendererInterface` (Task 2/8), `FakeVideoRenderer` (Task 5), `Video::musicAsset` (Task 1).
- Produces: `RenderVideoJob(int $videoId)`, dispatched via `RenderVideoJob::dispatch($id)`. Task 11 (Filament) dispatches this exact class.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Jobs;

use App\Domain\Video\Providers\FakeVideoRenderer;
use App\Domain\Video\RenderResult;
use App\Domain\Video\VideoRendererInterface;
use App\Jobs\RenderVideoJob;
use App\Models\ContentProject;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\Video;
use App\Models\VideoScene;
use App\Models\Voiceover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenderVideoJobTest extends TestCase
{
    use RefreshDatabase;

    private function bindFakeRenderer(?RenderResult $result = null): void
    {
        $this->app->bind(VideoRendererInterface::class, function () use ($result) {
            $fake = new FakeVideoRenderer;

            return $result !== null ? $fake->respondWith($result) : $fake;
        });
    }

    private function videoReadyForRendering(): Video
    {
        $project = ContentProject::factory()->create();
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'status' => VideoStatus::AssetsReady,
        ]);

        $asset = MediaAsset::factory()->create(['type' => MediaAssetType::Image]);
        VideoScene::factory()->create(['video_id' => $video->id, 'asset_id' => $asset->id]);

        Voiceover::factory()->create(['video_id' => $video->id]);

        $subtitle = MediaAsset::factory()->create(['type' => MediaAssetType::Subtitle]);
        $video->update(['subtitle_id' => $subtitle->id]);

        return $video;
    }

    public function test_it_renders_the_video_and_updates_it_from_the_render_result(): void
    {
        $this->bindFakeRenderer(new RenderResult(path: 'projects/1/renders/1.mp4', duration: 12.5, width: 1080, height: 1920));

        $video = $this->videoReadyForRendering();

        app()->call([new RenderVideoJob($video->id), 'handle']);

        $fresh = $video->fresh();
        $this->assertSame(VideoStatus::Rendered, $fresh->status);
        $this->assertSame('projects/1/renders/1.mp4', $fresh->file_path);
        $this->assertSame(13, $fresh->duration);
        $this->assertSame(1080, $fresh->width);
        $this->assertSame(1920, $fresh->height);
    }

    public function test_it_is_a_no_op_when_the_video_status_is_not_assets_ready(): void
    {
        $this->bindFakeRenderer();

        $video = $this->videoReadyForRendering();
        $video->update(['status' => VideoStatus::VoiceGenerated]);

        app()->call([new RenderVideoJob($video->id), 'handle']);

        $this->assertSame(VideoStatus::VoiceGenerated, $video->fresh()->status);
        $this->assertNull($video->fresh()->file_path);
    }

    public function test_it_is_a_no_op_when_there_is_no_subtitle(): void
    {
        $this->bindFakeRenderer();

        $video = $this->videoReadyForRendering();
        $video->update(['subtitle_id' => null]);

        app()->call([new RenderVideoJob($video->id), 'handle']);

        $this->assertSame(VideoStatus::AssetsReady, $video->fresh()->status);
        $this->assertNull($video->fresh()->file_path);
    }

    public function test_it_is_a_no_op_when_a_scene_has_no_asset(): void
    {
        $this->bindFakeRenderer();

        $video = $this->videoReadyForRendering();
        VideoScene::factory()->create(['video_id' => $video->id, 'asset_id' => null]);

        app()->call([new RenderVideoJob($video->id), 'handle']);

        $this->assertSame(VideoStatus::AssetsReady, $video->fresh()->status);
        $this->assertNull($video->fresh()->file_path);
    }

    public function test_it_is_a_no_op_when_there_is_no_voiceover(): void
    {
        $this->bindFakeRenderer();

        $project = ContentProject::factory()->create();
        $video = Video::factory()->create(['content_project_id' => $project->id, 'status' => VideoStatus::AssetsReady]);
        $asset = MediaAsset::factory()->create(['type' => MediaAssetType::Image]);
        VideoScene::factory()->create(['video_id' => $video->id, 'asset_id' => $asset->id]);
        $subtitle = MediaAsset::factory()->create(['type' => MediaAssetType::Subtitle]);
        $video->update(['subtitle_id' => $subtitle->id]);

        app()->call([new RenderVideoJob($video->id), 'handle']);

        $this->assertSame(VideoStatus::AssetsReady, $video->fresh()->status);
        $this->assertNull($video->fresh()->file_path);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RenderVideoJobTest`
Expected: FAIL — `Class "App\Jobs\RenderVideoJob" not found`.

- [ ] **Step 3: Implement `RenderVideoJob`**

```php
<?php

namespace App\Jobs;

use App\Domain\Video\VideoRendererInterface;
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

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 90, 180];
    }

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

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=RenderVideoJobTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --test app/Jobs/RenderVideoJob.php tests/Feature/Jobs/RenderVideoJobTest.php`
Expected: no style issues.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/RenderVideoJob.php tests/Feature/Jobs/RenderVideoJobTest.php
git commit -m "Add RenderVideoJob"
```

---

### Task 10: `QualityCheckVideoJob`

**Files:**
- Create: `app/Jobs/QualityCheckVideoJob.php`
- Test: `tests/Feature/Jobs/QualityCheckVideoJobTest.php`

**Interfaces:**
- Consumes: `VideoQualityCheckerInterface` (Task 2/8), `FakeVideoQualityChecker` (Task 5), `Video.quality_passed`/`quality_report` (Task 1).
- Produces: `QualityCheckVideoJob(int $videoId)`, dispatched via `QualityCheckVideoJob::dispatch($id)`. Task 11 (Filament) dispatches this exact class.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Jobs;

use App\Domain\Video\Providers\FakeVideoQualityChecker;
use App\Domain\Video\QualityCheckResult;
use App\Domain\Video\VideoQualityCheckerInterface;
use App\Jobs\QualityCheckVideoJob;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityCheckVideoJobTest extends TestCase
{
    use RefreshDatabase;

    private function bindFakeChecker(?QualityCheckResult $result = null): void
    {
        $this->app->bind(VideoQualityCheckerInterface::class, function () use ($result) {
            $fake = new FakeVideoQualityChecker;

            return $result !== null ? $fake->respondWith($result) : $fake;
        });
    }

    public function test_it_saves_the_quality_report_on_a_rendered_video(): void
    {
        $this->bindFakeChecker(new QualityCheckResult(
            passed: false,
            checks: ['has_video_stream' => true, 'resolution_matches' => false],
            notes: ['resolution mismatch'],
        ));

        $video = Video::factory()->create(['status' => VideoStatus::Rendered, 'file_path' => 'projects/1/renders/1.mp4']);

        app()->call([new QualityCheckVideoJob($video->id), 'handle']);

        $fresh = $video->fresh();
        $this->assertFalse($fresh->quality_passed);
        $this->assertSame(['has_video_stream' => true, 'resolution_matches' => false], $fresh->quality_report['checks']);
        $this->assertSame(['resolution mismatch'], $fresh->quality_report['notes']);
    }

    public function test_it_is_a_no_op_when_the_video_is_not_rendered(): void
    {
        $this->bindFakeChecker();

        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady]);

        app()->call([new QualityCheckVideoJob($video->id), 'handle']);

        $this->assertNull($video->fresh()->quality_passed);
    }

    public function test_re_running_it_overwrites_the_previous_report(): void
    {
        $video = Video::factory()->create([
            'status' => VideoStatus::Rendered,
            'file_path' => 'projects/1/renders/1.mp4',
            'quality_passed' => false,
            'quality_report' => ['checks' => ['has_video_stream' => false]],
        ]);

        $this->bindFakeChecker(new QualityCheckResult(passed: true, checks: ['has_video_stream' => true]));

        app()->call([new QualityCheckVideoJob($video->id), 'handle']);

        $fresh = $video->fresh();
        $this->assertTrue($fresh->quality_passed);
        $this->assertSame(['has_video_stream' => true], $fresh->quality_report['checks']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=QualityCheckVideoJobTest`
Expected: FAIL — `Class "App\Jobs\QualityCheckVideoJob" not found`.

- [ ] **Step 3: Implement `QualityCheckVideoJob`**

```php
<?php

namespace App\Jobs;

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

    public int $timeout = 120;

    public int $tries = 3;

    public int $uniqueFor = 150;

    public function __construct(public readonly int $videoId)
    {
        $this->onQueue('render');
    }

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

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=QualityCheckVideoJobTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --test app/Jobs/QualityCheckVideoJob.php tests/Feature/Jobs/QualityCheckVideoJobTest.php`
Expected: no style issues.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/QualityCheckVideoJob.php tests/Feature/Jobs/QualityCheckVideoJobTest.php
git commit -m "Add QualityCheckVideoJob"
```

---

### Task 11: Filament — "Render Video" / "Check Quality" actions and form fields

**Files:**
- Modify: `app/Filament/Resources/Videos/Tables/VideosTable.php`
- Modify: `app/Filament/Resources/Videos/Schemas/VideoForm.php`
- Test: `tests/Feature/Filament/VideoRenderActionTest.php`
- Test: `tests/Feature/Filament/VideoCheckQualityActionTest.php`

**Interfaces:**
- Consumes: `RenderVideoJob`, `QualityCheckVideoJob` (Tasks 9, 10), `Video::musicAsset` (Task 1), `MediaAssetType::Audio`.
- Produces: nothing consumed by later tasks — this is the last task in the plan.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Filament/VideoRenderActionTest.php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Jobs\RenderVideoJob;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class VideoRenderActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_video_action_dispatches_the_job_when_assets_ready_and_subtitle_exists(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake();

        $subtitle = MediaAsset::factory()->create(['type' => MediaAssetType::Subtitle]);
        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady, 'subtitle_id' => $subtitle->id]);

        Livewire::test(ListVideos::class)
            ->callTableAction('renderVideo', $video)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job) => $job->videoId === $video->id);
    }

    public function test_render_video_action_is_not_visible_without_a_subtitle(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady, 'subtitle_id' => null]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('renderVideo', $video);
    }

    public function test_render_video_action_is_not_visible_before_assets_are_ready(): void
    {
        $this->actingAs(User::factory()->create());

        $subtitle = MediaAsset::factory()->create(['type' => MediaAssetType::Subtitle]);
        $video = Video::factory()->create(['status' => VideoStatus::VoiceGenerated, 'subtitle_id' => $subtitle->id]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('renderVideo', $video);
    }
}
```

```php
<?php
// tests/Feature/Filament/VideoCheckQualityActionTest.php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Jobs\QualityCheckVideoJob;
use App\Models\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class VideoCheckQualityActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_quality_action_dispatches_the_job_when_rendered(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake();

        $video = Video::factory()->create(['status' => VideoStatus::Rendered]);

        Livewire::test(ListVideos::class)
            ->callTableAction('checkQuality', $video)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(QualityCheckVideoJob::class, fn (QualityCheckVideoJob $job) => $job->videoId === $video->id);
    }

    public function test_check_quality_action_is_not_visible_before_rendering(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('checkQuality', $video);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=VideoRenderActionTest && php artisan test --filter=VideoCheckQualityActionTest`
Expected: FAIL — `Table action "renderVideo" ... does not exist` / `"checkQuality" ... does not exist`.

- [ ] **Step 3: Add the two actions to `VideosTable.php`**

Add these two imports:

```php
use App\Jobs\QualityCheckVideoJob;
use App\Jobs\RenderVideoJob;
```

Add these two `Action::make(...)` entries inside `recordActions([...])`, right after the existing `generateSubtitles` action and before `EditAction::make()`:

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

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=VideoRenderActionTest && php artisan test --filter=VideoCheckQualityActionTest`
Expected: PASS (5 tests total).

- [ ] **Step 5: Add form fields to `VideoForm.php`**

Add these imports:

```php
use App\Models\Enums\MediaAssetType;
use Filament\Forms\Components\Toggle;
```

Add these fields right after `TextInput::make('thumbnail_path')` and before `TextInput::make('metadata')`:

```php
                Select::make('music_asset_id')
                    ->label('Background Music')
                    ->relationship(
                        name: 'musicAsset',
                        titleAttribute: 'path',
                        modifyQueryUsing: fn ($query) => $query->where('type', MediaAssetType::Audio),
                    )
                    ->searchable()
                    ->preload(),
                Toggle::make('quality_passed')
                    ->label('Quality Passed')
                    ->disabled(),
```

Add this field after `TextInput::make('metadata')` and before `Textarea::make('error_message')`:

```php
                TextInput::make('quality_report')
                    ->label('Quality Report')
                    ->disabled()
                    ->formatStateUsing(fn ($state) => $state ? json_encode($state) : null),
```

- [ ] **Step 6: Manually verify the form renders**

Run: `php artisan route:list | grep videos` to confirm the resource routes still resolve, then run the full suite once more:

Run: `php artisan test`
Expected: all tests pass (no regression from the form changes — Filament form fields aren't covered by a dedicated test in this codebase beyond the table-action tests already written).

- [ ] **Step 7: Run Pint on all touched Filament files**

Run: `vendor/bin/pint --test app/Filament/Resources/Videos tests/Feature/Filament/VideoRenderActionTest.php tests/Feature/Filament/VideoCheckQualityActionTest.php`
Expected: no style issues.

- [ ] **Step 8: Commit**

```bash
git add app/Filament/Resources/Videos/Tables/VideosTable.php app/Filament/Resources/Videos/Schemas/VideoForm.php tests/Feature/Filament/VideoRenderActionTest.php tests/Feature/Filament/VideoCheckQualityActionTest.php
git commit -m "Add Render Video / Check Quality Filament actions and music/quality form fields"
```

---

### Task 12: Final verification and ROADMAP update

**Files:**
- Modify: `ROADMAP.md`

- [ ] **Step 1: Run the full verification suite**

Run:
```bash
php artisan test
vendor/bin/pint --test
php artisan route:list
php artisan migrate:fresh --seed
```
Expected: all green, no errors, seed completes.

- [ ] **Step 2: Update `ROADMAP.md`**

Mark the three Phase 3e bullets under "Phase 3 — Video" as done (`[x]`), add the "Phase 3e, завершено (<date>)" line with links to this plan and the spec, and add a final review notes section listing the carried-over gaps this plan intentionally did not close (permanent-failure status not reset to `Failed`, no `Notification::sendToDatabase()`, worker/horizon Docker duplication still open, exact xfade filter structure — confirm during implementation it behaves as expected on a real multi-scene video since it was only verified against `Process::fake()`).

- [ ] **Step 3: Commit**

```bash
git add ROADMAP.md
git commit -m "Mark Phase 3e complete in roadmap"
```
