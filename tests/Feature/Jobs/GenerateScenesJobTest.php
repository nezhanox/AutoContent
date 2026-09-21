<?php

namespace Tests\Feature\Jobs;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Domain\Video\Exceptions\SceneGenerationFailedException;
use App\Jobs\GenerateScenesJob;
use App\Jobs\GenerateVoiceoverJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ScriptStatus;
use App\Models\Enums\VideoStatus;
use App\Models\Script;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoScene;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerateScenesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

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

        Queue::assertPushed(GenerateVoiceoverJob::class, fn (GenerateVoiceoverJob $job) => $job->videoId === $video->id);
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

    public function test_a_failed_regeneration_leaves_existing_scenes_untouched(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $fake = (new FakeLlmProvider)->respondWith($this->fakeScenesResponse());
        $this->app->instance(FakeLlmProvider::class, $fake);

        $script = $this->scriptWithCompletedStatus();

        app()->call([new GenerateScenesJob($script->id), 'handle']);

        $video = Video::where('script_id', $script->id)->sole();
        $this->assertSame(2, VideoScene::where('video_id', $video->id)->count());

        $fake->respondWith('not valid json, and will exhaust all repair attempts');

        $this->expectException(SceneGenerationFailedException::class);

        try {
            app()->call([new GenerateScenesJob($script->id), 'handle']);
        } finally {
            $this->assertSame(2, VideoScene::where('video_id', $video->id)->count());
        }
    }

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
        $this->assertSame('scenes', $video->fresh()->failed_stage);
        $this->assertSame('boom', $video->fresh()->error_message);
    }
}
