# Console Video Show Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the self-built Console admin (`/console/videos`) a per-video
detail page — video player, voiceover audio player, background music,
subtitles text, script, scenes, quality report, and error info — closing
the gap the user hit: Console's Videos list has no way to actually watch a
video or inspect what Filament's `VideoResource` view/edit form already
shows.

**Architecture:** Read-only only (user explicitly declined an editable
form for this pass). One new GET route/controller action
(`VideoController::show()`) mirrors the exact set of fields `VideoForm.php`
already surfaces in Filament — reusing the same `Storage::disk(...)
->temporaryUrl()` mechanism Filament uses for the video/audio `<video>`/
`<audio>` elements (the `local` disk already has `'serve' => true` in
`config/filesystems.php`, so this works with zero new plumbing). One new
Inertia page (`Videos/Show.tsx`) renders it using the Console's existing
`Card`/`Badge`/`Button`/`Table` primitives — no new UI components. Clicking
a row in `Videos/Index.tsx` links to the new page.

**Tech Stack:** Laravel 12 / PHP 8.4 (Inertia controller + route), React 19 +
TypeScript (Inertia page), existing Console design system
(`resources/js/console/components/ui/*`), PHPUnit Feature test with
`Inertia\Testing\AssertableInertia`.

**Spec:** No separate spec file — bounded change approved in chat directly
(brainstorming skill, 2026-09-25): read-only detail page only, no inline
editing, no per-stage manual pipeline-trigger actions (Filament's
"Generate Voiceover"/"Collect Assets"/etc. buttons are explicitly NOT being
ported — Console's existing generic "Retry" already covers the
failed/stuck-video recovery case).

## Global Constraints

- Read-only page. No form, no field editing, no new mutation endpoints.
- No new UI primitives — build the page from the existing `Card`,
  `CardHeader`, `CardTitle`, `CardContent`, `Badge`, `Button`, `Table`
  family already in `resources/js/console/components/ui/`.
- No new artisan commands, no migrations, no changes to any Job or
  pipeline behavior.
- Route naming/style matches the existing `routes/panel.php` group exactly
  (same `HandleInertiaRequests` + `auth` middleware group, same
  `console.videos.*` name prefix).
- Style: `vendor/bin/pint --test` (PHP) and `npx tsc --noEmit` (TypeScript)
  must both pass clean. `npm run build` must succeed.
- Test with `RefreshDatabase` + `Inertia\Testing\AssertableInertia`, the
  same pattern already used in `tests/Feature/Console/VideoControllerTest.php`.

---

### Task 1: `VideoController::show()` + route

**Files:**
- Modify: `app/Http/Controllers/Console/VideoController.php`
- Modify: `routes/panel.php`
- Test: `tests/Feature/Console/VideoControllerTest.php`

**Interfaces:**
- Produces: `GET /console/videos/{video}` (route name `console.videos.show`)
  → Inertia page `Videos/Show` with a single `video` prop of this exact
  shape (Task 2's frontend consumes this verbatim):

```ts
interface VideoShowProps {
    video: {
        id: number;
        title: string;
        description: string;
        status: string;
        stageLabel: string;
        stageColor: 'danger' | 'success' | 'warning' | 'default';
        canRetry: boolean;
        channel: string | null;
        channelId: number | null;
        idea: string | null;
        duration: number | null;
        width: number | null;
        height: number | null;
        createdAt: string | null;
        videoUrl: string | null;
        voiceoverUrl: string | null;
        musicAssetLabel: string | null;
        scriptText: string | null;
        subtitlesText: string | null;
        scenes: Array<{
            order: number;
            type: string;
            duration: number;
            text: string;
            visualQuery: string | null;
        }>;
        qualityPassed: boolean | null;
        qualityReport: Record<string, unknown> | null;
        errorMessage: string | null;
        failedStage: string | null;
    };
}
```

- [ ] **Step 1: Write the failing Feature test**

Open `tests/Feature/Console/VideoControllerTest.php` and add these imports
alongside the existing ones at the top of the file:

```php
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoSceneType;
use App\Models\MediaAsset;
use App\Models\VideoScene;
use App\Models\Voiceover;
use Illuminate\Support\Facades\Storage;
```

Then add this test method to the `VideoControllerTest` class (anywhere
inside the class body, e.g. right after `test_index_lists_videos_with_channel_idea_and_stage`):

```php
    public function test_show_returns_full_video_details_for_the_view_page(): void
    {
        $this->actingAs(User::factory()->create());

        Storage::fake(config('filesystems.default'));
        $disk = Storage::disk(config('filesystems.default'));
        $disk->put('renders/1.mp4', 'fake-video-bytes');
        $disk->put('voice/1.mp3', 'fake-audio-bytes');
        $disk->put('subs/1.srt', "1\n00:00:00,000 --> 00:00:01,000\nHello");

        $project = ContentProject::factory()->create(['name' => 'Tech Channel']);
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id, 'title' => 'AI trends']);
        $script = Script::factory()->create(['content_idea_id' => $idea->id, 'content' => 'Full script text']);
        $musicAsset = MediaAsset::factory()->create(['type' => MediaAssetType::Audio, 'path' => 'music/track.mp3']);
        $subtitleAsset = MediaAsset::factory()->create(['type' => MediaAssetType::Subtitle, 'path' => 'subs/1.srt']);

        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'script_id' => $script->id,
            'title' => 'AI Trends 2026',
            'status' => VideoStatus::Rendered,
            'file_path' => 'renders/1.mp4',
            'music_asset_id' => $musicAsset->id,
            'subtitle_id' => $subtitleAsset->id,
            'quality_passed' => true,
            'quality_report' => ['checks' => ['has_audio_stream' => true]],
        ]);

        Voiceover::factory()->create(['video_id' => $video->id, 'file_path' => 'voice/1.mp3']);
        VideoScene::factory()->create([
            'video_id' => $video->id,
            'order' => 0,
            'type' => VideoSceneType::Hook,
            'duration' => 5,
            'text' => 'Hook text',
            'visual_query' => 'query 1',
        ]);

        $this->get("/console/videos/{$video->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Videos/Show')
                ->where('video.id', $video->id)
                ->where('video.title', 'AI Trends 2026')
                ->where('video.channel', 'Tech Channel')
                ->where('video.channelId', $project->id)
                ->where('video.idea', 'AI trends')
                ->where('video.status', 'rendered')
                ->where('video.canRetry', false)
                ->where('video.scriptText', 'Full script text')
                ->where('video.subtitlesText', "1\n00:00:00,000 --> 00:00:01,000\nHello")
                ->where('video.musicAssetLabel', 'music/track.mp3')
                ->where('video.qualityPassed', true)
                ->where('video.qualityReport', ['checks' => ['has_audio_stream' => true]])
                ->where('video.videoUrl', fn ($value) => is_string($value) && str_contains($value, 'renders/1.mp4'))
                ->where('video.voiceoverUrl', fn ($value) => is_string($value) && str_contains($value, 'voice/1.mp3'))
                ->has('video.scenes', 1)
                ->where('video.scenes.0.order', 0)
                ->where('video.scenes.0.type', 'hook')
                ->where('video.scenes.0.duration', 5)
                ->where('video.scenes.0.text', 'Hook text')
                ->where('video.scenes.0.visualQuery', 'query 1')
            );
    }

    public function test_show_handles_a_video_with_no_media_yet(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create();
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'status' => VideoStatus::Draft,
            'file_path' => null,
            'music_asset_id' => null,
            'subtitle_id' => null,
        ]);

        $this->get("/console/videos/{$video->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Videos/Show')
                ->where('video.videoUrl', null)
                ->where('video.voiceoverUrl', null)
                ->where('video.musicAssetLabel', null)
                ->where('video.subtitlesText', null)
                ->has('video.scenes', 0)
            );
    }
```

- [ ] **Step 2: Run the tests and verify they fail**

Run: `php artisan test --filter=VideoControllerTest`
Expected: FAIL — the two new tests error because route `console.videos.show`
/ page `Videos/Show` don't exist yet (the other existing tests in this
file still pass).

- [ ] **Step 3: Add the route**

In `routes/panel.php`, inside the `auth` middleware group, add this line
directly after the existing `Route::get('videos', ...)` line (before the
`generate`/`retry` POST routes or after — position among the `videos*`
routes does not matter since HTTP methods differ):

```php
        Route::get('videos/{video}', [VideoController::class, 'show'])->name('console.videos.show');
```

- [ ] **Step 4: Implement `VideoController::show()`**

In `app/Http/Controllers/Console/VideoController.php`, add this import
alongside the existing ones at the top of the file:

```php
use Illuminate\Support\Facades\Storage;
```

Then add this method to the `VideoController` class (e.g. right after
`index()`):

```php
    public function show(Video $video): Response
    {
        $video->load(['contentProject:id,name', 'contentIdea:id,title', 'script:id,content', 'scenes', 'voiceover', 'subtitle', 'musicAsset']);

        $disk = Storage::disk(config('filesystems.default'));

        return Inertia::render('Videos/Show', [
            'video' => [
                'id' => $video->id,
                'title' => $video->title,
                'description' => $video->description,
                'status' => $video->status->value,
                'stageLabel' => $video->currentStageLabel(),
                'stageColor' => $video->stageBadgeColor(),
                'canRetry' => in_array($video->status, [VideoStatus::Failed, VideoStatus::Rendering], true),
                'channel' => $video->contentProject?->name,
                'channelId' => $video->contentProject?->id,
                'idea' => $video->contentIdea?->title,
                'duration' => $video->duration,
                'width' => $video->width,
                'height' => $video->height,
                'createdAt' => $video->created_at?->toIso8601String(),
                'videoUrl' => $video->file_path ? $disk->temporaryUrl($video->file_path, now()->addMinutes(30)) : null,
                'voiceoverUrl' => $video->voiceover?->file_path ? $disk->temporaryUrl($video->voiceover->file_path, now()->addMinutes(30)) : null,
                'musicAssetLabel' => $video->musicAsset?->path,
                'scriptText' => $video->script?->content,
                'subtitlesText' => $video->subtitle?->path ? $disk->get($video->subtitle->path) : null,
                'scenes' => $video->scenes->map(fn ($scene) => [
                    'order' => $scene->order,
                    'type' => $scene->type->value,
                    'duration' => $scene->duration,
                    'text' => $scene->text,
                    'visualQuery' => $scene->visual_query,
                ])->all(),
                'qualityPassed' => $video->quality_passed,
                'qualityReport' => $video->quality_report,
                'errorMessage' => $video->error_message,
                'failedStage' => $video->failed_stage,
            ],
        ]);
    }
```

Note: `Video` and `Response`/`Inertia` are already imported at the top of
this file for the existing `index()` method — do not add duplicate imports.

- [ ] **Step 5: Run the tests and verify they pass**

Run: `php artisan test --filter=VideoControllerTest`
Expected: PASS (7/7 — the 5 pre-existing tests plus the 2 new ones).

- [ ] **Step 6: Pint + commit**

Run: `vendor/bin/pint app/Http/Controllers/Console/VideoController.php routes/panel.php tests/Feature/Console/VideoControllerTest.php`

```bash
git add app/Http/Controllers/Console/VideoController.php routes/panel.php tests/Feature/Console/VideoControllerTest.php
git commit -m "feat(console): add video show endpoint with full media/detail payload"
```

---

### Task 2: `Videos/Show.tsx` page + clickable rows on `Videos/Index.tsx`

**Files:**
- Create: `resources/js/console/Pages/Videos/Show.tsx`
- Modify: `resources/js/console/Pages/Videos/Index.tsx`

**Interfaces:**
- Consumes: `GET /console/videos/{id}` → Inertia page `Videos/Show` with
  the `VideoShowProps`/`video` shape defined in Task 1 (already committed
  and live at this point).
- Consumes: `POST /console/videos/{id}/retry` (already exists, used by
  `Index.tsx` today — reuse the exact same call for the Retry button on
  the new Show page).
- Produces: nothing new for later tasks — this is the last task in the plan.

- [ ] **Step 1: Create the Show page**

Create `resources/js/console/Pages/Videos/Show.tsx`:

```tsx
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, RefreshCw } from 'lucide-react';
import { AppLayout } from '../../components/AppLayout';
import { Button } from '../../components/ui/button';
import { Badge } from '../../components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '../../components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeadCell, TableRow } from '../../components/ui/table';

interface VideoScene {
    order: number;
    type: string;
    duration: number;
    text: string;
    visualQuery: string | null;
}

interface VideoDetail {
    id: number;
    title: string;
    description: string;
    status: string;
    stageLabel: string;
    stageColor: 'danger' | 'success' | 'warning' | 'default';
    canRetry: boolean;
    channel: string | null;
    channelId: number | null;
    idea: string | null;
    duration: number | null;
    width: number | null;
    height: number | null;
    createdAt: string | null;
    videoUrl: string | null;
    voiceoverUrl: string | null;
    musicAssetLabel: string | null;
    scriptText: string | null;
    subtitlesText: string | null;
    scenes: VideoScene[];
    qualityPassed: boolean | null;
    qualityReport: Record<string, unknown> | null;
    errorMessage: string | null;
    failedStage: string | null;
}

export default function VideoShow({ video }: { video: VideoDetail }) {
    function retry() {
        router.post(`/console/videos/${video.id}/retry`);
    }

    return (
        <AppLayout title={video.title}>
            <div className="mb-5 flex items-center justify-between">
                <Link
                    href="/console/videos"
                    className="inline-flex items-center gap-1.5 text-sm text-console-text-muted hover:text-console-text"
                >
                    <ArrowLeft className="h-4 w-4" strokeWidth={2.25} />
                    All videos
                </Link>
                {video.canRetry && (
                    <Button variant="outline" size="sm" onClick={retry}>
                        <RefreshCw className="h-3.5 w-3.5" strokeWidth={2.25} />
                        Retry
                    </Button>
                )}
            </div>

            <div className="space-y-5">
                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                        <div className="flex items-center gap-2">
                            <Badge>{video.status}</Badge>
                            <Badge variant={video.stageColor}>{video.stageLabel}</Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                        <div>
                            <div className="text-console-text-muted">Channel</div>
                            <div className="font-medium text-console-text">{video.channel ?? '—'}</div>
                        </div>
                        <div>
                            <div className="text-console-text-muted">Idea</div>
                            <div className="font-medium text-console-text">{video.idea ?? '—'}</div>
                        </div>
                        <div>
                            <div className="text-console-text-muted">Dimensions</div>
                            <div className="font-medium text-console-text">
                                {video.width && video.height ? `${video.width}×${video.height}` : '—'}
                            </div>
                        </div>
                        <div>
                            <div className="text-console-text-muted">Duration</div>
                            <div className="font-medium text-console-text">{video.duration ? `${video.duration}s` : '—'}</div>
                        </div>
                        <div>
                            <div className="text-console-text-muted">Background music</div>
                            <div className="font-medium text-console-text">{video.musicAssetLabel ?? '—'}</div>
                        </div>
                        <div>
                            <div className="text-console-text-muted">Created</div>
                            <div className="font-medium text-console-text">{video.createdAt ?? '—'}</div>
                        </div>
                        {video.description && (
                            <div className="col-span-full">
                                <div className="text-console-text-muted">Description</div>
                                <div className="text-console-text">{video.description}</div>
                            </div>
                        )}
                        {video.errorMessage && (
                            <div className="col-span-full rounded-lg bg-console-danger-soft px-4 py-2 text-console-danger">
                                {video.failedStage ? `[${video.failedStage}] ` : ''}
                                {video.errorMessage}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Video</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {video.videoUrl ? (
                            <video controls preload="metadata" style={{ width: '100%', maxWidth: 360 }} src={video.videoUrl} />
                        ) : (
                            <p className="text-sm text-console-text-muted">Not rendered yet.</p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Voiceover</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {video.voiceoverUrl ? (
                            <audio controls preload="metadata" style={{ width: '100%', maxWidth: 480 }} src={video.voiceoverUrl} />
                        ) : (
                            <p className="text-sm text-console-text-muted">No voiceover yet.</p>
                        )}
                    </CardContent>
                </Card>

                {video.scriptText && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Script</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="whitespace-pre-wrap text-sm text-console-text">{video.scriptText}</p>
                        </CardContent>
                    </Card>
                )}

                {video.scenes.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Scenes</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHead>
                                    <TableRow>
                                        <TableHeadCell className="pl-5">#</TableHeadCell>
                                        <TableHeadCell>Type</TableHeadCell>
                                        <TableHeadCell>Duration</TableHeadCell>
                                        <TableHeadCell>Text</TableHeadCell>
                                        <TableHeadCell className="pr-5">Visual query</TableHeadCell>
                                    </TableRow>
                                </TableHead>
                                <TableBody>
                                    {video.scenes.map((scene) => (
                                        <TableRow key={scene.order}>
                                            <TableCell className="pl-5">{scene.order}</TableCell>
                                            <TableCell><Badge>{scene.type}</Badge></TableCell>
                                            <TableCell>{scene.duration}s</TableCell>
                                            <TableCell className="max-w-sm">{scene.text}</TableCell>
                                            <TableCell className="pr-5 text-console-text-muted">{scene.visualQuery ?? '—'}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                {video.subtitlesText && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Subtitles</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <pre className="max-h-64 overflow-auto whitespace-pre-wrap text-xs text-console-text">{video.subtitlesText}</pre>
                        </CardContent>
                    </Card>
                )}

                {video.qualityReport && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Quality report</CardTitle>
                            <Badge variant={video.qualityPassed ? 'success' : 'danger'}>
                                {video.qualityPassed ? 'Passed' : 'Failed'}
                            </Badge>
                        </CardHeader>
                        <CardContent>
                            <pre className="max-h-64 overflow-auto whitespace-pre-wrap text-xs text-console-text">
                                {JSON.stringify(video.qualityReport, null, 2)}
                            </pre>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
```

- [ ] **Step 2: Make Index.tsx rows link to the Show page**

In `resources/js/console/Pages/Videos/Index.tsx`, add `Link` to the
existing `@inertiajs/react` import at the top of the file — change:

```tsx
import { router, useForm } from '@inertiajs/react';
```

to:

```tsx
import { Link, router, useForm } from '@inertiajs/react';
```

Then change the row's `title` cell so it links to the detail page —
replace:

```tsx
                                        <TableCell className="max-w-xs truncate font-medium">{video.title}</TableCell>
```

with:

```tsx
                                        <TableCell className="max-w-xs truncate font-medium">
                                            <Link href={`/console/videos/${video.id}`} className="hover:underline">
                                                {video.title}
                                            </Link>
                                        </TableCell>
```

- [ ] **Step 3: Typecheck and build**

Run: `npx tsc --noEmit`
Expected: no errors.

Run: `npm run build`
Expected: build succeeds.

- [ ] **Step 4: Manual verification via the real test suite**

Run: `php artisan test --filter=VideoControllerTest`
Expected: PASS (7/7 — confirms Task 1's backend contract still holds
after this frontend-only task; this task adds no new backend tests since
it's a pure frontend page + a one-line change to an existing page).

- [ ] **Step 5: Pint + commit**

Run: `vendor/bin/pint --test` (repo-wide check — this task touches no PHP,
but confirms Task 1's changes are still clean)

```bash
git add resources/js/console/Pages/Videos/Show.tsx resources/js/console/Pages/Videos/Index.tsx
git commit -m "feat(console): add video detail page with player, audio, scenes, and quality report"
```
