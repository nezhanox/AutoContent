<?php

namespace Tests\Feature\Console;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Jobs\GenerateScriptJob;
use App\Jobs\RenderVideoJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\Enums\VideoStatus;
use App\Models\Script;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
