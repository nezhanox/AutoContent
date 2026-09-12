<?php

namespace Tests\Feature\Filament;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Filament\Resources\ContentIdeas\Pages\ListContentIdeas;
use App\Jobs\GenerateScriptJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ContentIdeaActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_idea_action_creates_a_content_idea(): void
    {
        $this->actingAs(User::factory()->create());

        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'T', 'topic' => 'ai', 'score' => 50])
            );
        });

        $project = ContentProject::factory()->create(['settings' => []]);

        Livewire::test(ListContentIdeas::class)
            ->callAction('generateIdea', data: [
                'content_project_id' => $project->id,
                'topic' => 'ai',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('content_ideas', [
            'content_project_id' => $project->id,
            'title' => 'T',
        ]);
    }

    public function test_approve_action_transitions_new_idea_to_approved(): void
    {
        $this->actingAs(User::factory()->create());

        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::New]);

        Livewire::test(ListContentIdeas::class)
            ->callTableAction('approve', $idea)
            ->assertHasNoTableActionErrors();

        $this->assertSame(ContentIdeaStatus::Approved, $idea->fresh()->status);
    }

    public function test_reject_action_transitions_new_idea_to_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::New]);

        Livewire::test(ListContentIdeas::class)
            ->callTableAction('reject', $idea)
            ->assertHasNoTableActionErrors();

        $this->assertSame(ContentIdeaStatus::Rejected, $idea->fresh()->status);
    }

    public function test_generate_script_action_dispatches_the_job_only_when_approved(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::Approved]);

        Livewire::test(ListContentIdeas::class)
            ->callTableAction('generateScript', $idea)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(GenerateScriptJob::class, fn (GenerateScriptJob $job) => $job->contentIdeaId === $idea->id);
    }

    public function test_generate_script_action_is_not_visible_for_a_new_idea(): void
    {
        $this->actingAs(User::factory()->create());

        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::New]);

        Livewire::test(ListContentIdeas::class)
            ->assertTableActionHidden('generateScript', $idea);
    }
}
