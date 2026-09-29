<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CreateClipVideosJob;
use App\Jobs\RenderVideoJob;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\SourceVideoStatus;
use App\Models\Enums\VideoSceneType;
use App\Models\Enums\VideoStatus;
use App\Models\SourceClip;
use App\Models\SourceVideo;
use App\Models\User;
use App\Models\Video;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CreateClipVideosJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake(config('filesystems.default'));
    }

    private function selected(): SourceVideo
    {
        $segments = [
            ['start' => 0.0, 'end' => 10.0, 'text' => 'Intro before the clip.'],
            ['start' => 12.0, 'end' => 20.0, 'text' => 'First clip words here.'],
            ['start' => 30.0, 'end' => 40.0, 'text' => 'Second clip words here.'],
        ];

        return SourceVideo::factory()->create([
            'status' => SourceVideoStatus::ClipsSelected,
            'youtube_id' => 'abc123',
            'transcript' => ['language' => 'en', 'segments' => $segments, 'utterances' => []],
        ]);
    }

    public function test_it_creates_a_video_per_clip_and_dispatches_render(): void
    {
        $source = $this->selected();
        $a = SourceClip::factory()->create(['source_video_id' => $source->id, 'start' => 10.0, 'end' => 25.4, 'title' => 'Clip A', 'hook' => 'Hook A']);
        $b = SourceClip::factory()->create(['source_video_id' => $source->id, 'start' => 28.0, 'end' => 45.0, 'title' => 'Clip B']);

        app()->call([new CreateClipVideosJob($source->id), 'handle']);

        $this->assertSame(SourceVideoStatus::ClipsCreated, $source->refresh()->status);
        $this->assertSame(2, Video::count());

        $a->refresh();
        $video = $a->video;
        $this->assertSame(VideoStatus::AssetsReady, $video->status);
        $this->assertSame($a->id, $video->source_clip_id);
        $this->assertNull($video->content_idea_id);
        $this->assertNull($video->script_id);
        $this->assertSame($source->sourceChannel->content_project_id, $video->content_project_id);
        $this->assertSame('Clip A', $video->title);
        $this->assertSame('Hook A', $video->description);
        $this->assertSame('abc123', $video->metadata['youtube_id']);
        $this->assertSame($source->id, $video->metadata['source_video_id']);

        $scenes = $video->scenes;
        $this->assertCount(1, $scenes);
        $this->assertSame(VideoSceneType::Broll, $scenes[0]->type);
        $this->assertSame(15, $scenes[0]->duration);
        $this->assertSame('Clip A', $scenes[0]->text);
        $this->assertNull($scenes[0]->asset_id);

        $subtitle = $video->subtitle;
        $this->assertSame(MediaAssetType::Subtitle, $subtitle->type);
        $segments = $subtitle->metadata['segments'];
        $this->assertEqualsWithDelta(2.0, $segments[0]['start'], 0.001);
        $this->assertSame('en', $subtitle->metadata['language']);
        $path = "projects/{$video->content_project_id}/subtitles/{$video->id}.srt";
        $this->assertSame($path, $subtitle->path);
        Storage::disk(config('filesystems.default'))->assertExists($path);
        $this->assertSame(hash('sha256', Storage::disk(config('filesystems.default'))->get($path)), $subtitle->hash);

        Queue::assertPushed(RenderVideoJob::class, 2);
        $this->assertNotNull($b->refresh()->video_id);
    }

    public function test_it_is_idempotent_for_clips_that_already_have_a_video(): void
    {
        $source = $this->selected();
        $existing = Video::factory()->create();
        SourceClip::factory()->create(['source_video_id' => $source->id, 'video_id' => $existing->id]);
        $fresh = SourceClip::factory()->create(['source_video_id' => $source->id, 'start' => 10.0, 'end' => 25.0]);
        $before = Video::count();

        app()->call([new CreateClipVideosJob($source->id), 'handle']);

        $this->assertSame($before + 1, Video::count());
        $this->assertNotNull($fresh->refresh()->video_id);
        Queue::assertPushed(RenderVideoJob::class, 1);
        $this->assertSame(SourceVideoStatus::ClipsCreated, $source->refresh()->status);
    }

    public function test_it_skips_when_status_is_not_clips_selected(): void
    {
        $source = $this->selected();
        $source->update(['status' => SourceVideoStatus::ClipsCreated]);
        SourceClip::factory()->create(['source_video_id' => $source->id]);

        app()->call([new CreateClipVideosJob($source->id), 'handle']);

        $this->assertSame(0, Video::count());
        Queue::assertNothingPushed();
    }

    public function test_failed_marks_the_video_failed_and_notifies(): void
    {
        Notification::fake();
        User::factory()->create();
        $source = $this->selected();

        (new CreateClipVideosJob($source->id))->failed(new \RuntimeException('boom'));

        $source->refresh();
        $this->assertSame(SourceVideoStatus::Failed, $source->status);
        $this->assertSame('create', $source->failed_stage);
        Notification::assertSentTo(User::all(), PipelineJobFailedNotification::class);
    }
}
