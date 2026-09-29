<?php

namespace Tests\Feature\Console;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Jobs\GenerateScriptJob;
use App\Jobs\RenderVideoJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoSceneType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\Script;
use App\Models\SourceClip;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoScene;
use App\Models\Voiceover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VideoControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_videos_with_channel_idea_and_stage(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create(['name' => 'Tech Channel']);
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id, 'title' => 'AI trends']);
        // Video::factory()'s script_id default (Script::factory()) would otherwise chain into a
        // fresh, unrelated ContentIdea/ContentProject; pin it to this idea so only one channel exists.
        $script = Script::factory()->create(['content_idea_id' => $idea->id]);
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'script_id' => $script->id,
            'status' => VideoStatus::Failed,
            'failed_stage' => 'render',
            'title' => 'AI Trends 2026',
        ]);

        $this->get('/console/videos')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Videos/Index')
                ->has('videos', 1)
                ->where('videos.0.title', 'AI Trends 2026')
                ->where('videos.0.channel', 'Tech Channel')
                ->where('videos.0.idea', 'AI trends')
                ->where('videos.0.stageLabel', $video->fresh()->currentStageLabel())
                ->where('videos.0.stageColor', 'danger')
                ->has('channels', 1)
            );
    }

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

    public function test_index_and_show_handle_a_clip_video_without_idea_or_script(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create([
            'content_idea_id' => null,
            'script_id' => null,
            'source_clip_id' => SourceClip::factory()->create()->id,
        ]);

        $this->get('/console/videos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Videos/Index')
                ->has('videos', 1)
                ->where('videos.0.idea', null)
            );

        $this->get("/console/videos/{$video->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Videos/Show')
                ->where('video.idea', null)
                ->where('video.scriptText', null)
            );
    }

    public function test_generate_creates_an_approved_idea_and_dispatches_script_generation(): void
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

        // 'settings' => [] avoids ContentProjectFactory's default ai.default.provider ('openai'),
        // which would otherwise outrank the 'fake' provider set via config() above (see the
        // identical pattern in GenerateContentIdeaServiceTest and VideoGenerateActionTest).
        $project = ContentProject::factory()->create(['settings' => []]);

        $this->post('/console/videos/generate', [
            'content_project_id' => $project->id,
            'topic' => 'ai',
        ])->assertRedirect();

        $idea = ContentIdea::where('content_project_id', $project->id)->sole();
        $this->assertSame(ContentIdeaStatus::Approved, $idea->status);

        Queue::assertPushed(GenerateScriptJob::class, fn (GenerateScriptJob $job) => $job->contentIdeaId === $idea->id);
    }

    public function test_index_marks_failed_and_stalled_videos_as_retryable(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create();
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);
        Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'status' => VideoStatus::Failed,
            'failed_stage' => 'render',
        ]);
        Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'status' => VideoStatus::Draft,
        ]);

        $this->get('/console/videos')
            ->assertInertia(fn (Assert $page) => $page
                ->has('videos', 2)
                ->where('videos.0.canRetry', false)
                ->where('videos.1.canRetry', true)
            );
    }

    public function test_retry_dispatches_the_job_for_the_failed_stage_and_resets_status(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $project = ContentProject::factory()->create();
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'status' => VideoStatus::Failed,
            'failed_stage' => 'render',
            'error_message' => 'ffmpeg exploded',
        ]);

        $this->post("/console/videos/{$video->id}/retry")->assertRedirect();

        $video->refresh();
        $this->assertSame(VideoStatus::AssetsReady, $video->status);
        $this->assertNull($video->failed_stage);
        $this->assertNull($video->error_message);

        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job) => $job->videoId === $video->id);
    }

    public function test_retry_without_a_failed_stage_returns_an_error(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create();
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'status' => VideoStatus::Draft,
            'failed_stage' => null,
        ]);

        $this->post("/console/videos/{$video->id}/retry")->assertSessionHasErrors('video');
    }
}
