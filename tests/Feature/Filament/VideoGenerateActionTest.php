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
