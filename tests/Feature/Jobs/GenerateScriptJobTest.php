<?php

namespace Tests\Feature\Jobs;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Jobs\GenerateScriptJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\Enums\ScriptStatus;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateScriptJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_completed_script_and_marks_the_idea_used(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'T', 'hook' => 'H', 'script' => 'Body', 'estimated_duration' => 55, 'cta' => 'Follow'])
            );
        });

        $idea = ContentIdea::factory()->create([
            'status' => ContentIdeaStatus::Approved,
            'content_project_id' => ContentProject::factory()->create(['settings' => []]),
        ]);

        $job = new GenerateScriptJob($idea->id);
        app()->call([$job, 'handle']);

        $idea->refresh();
        $this->assertSame(ContentIdeaStatus::Used, $idea->status);

        $script = Script::where('content_idea_id', $idea->id)->sole();
        $this->assertSame(ScriptStatus::Completed, $script->status);
        $this->assertSame('Body', $script->content);
        $this->assertSame('H', $script->hook);
        $this->assertSame(55, $script->estimated_duration);
        $this->assertSame('Follow', $script->metadata['cta']);
    }

    public function test_it_is_a_no_op_when_the_idea_is_not_approved(): void
    {
        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::New]);

        $job = new GenerateScriptJob($idea->id);
        app()->call([$job, 'handle']);

        $this->assertDatabaseCount('scripts', 0);
        $this->assertSame(ContentIdeaStatus::New, $idea->fresh()->status);
    }

    public function test_calling_handle_twice_does_not_create_a_duplicate_script(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'T', 'hook' => 'H', 'script' => 'Body', 'estimated_duration' => 55, 'cta' => 'Follow'])
            );
        });

        $idea = ContentIdea::factory()->create([
            'status' => ContentIdeaStatus::Approved,
            'content_project_id' => ContentProject::factory()->create(['settings' => []]),
        ]);

        app()->call([new GenerateScriptJob($idea->id), 'handle']);
        app()->call([new GenerateScriptJob($idea->id), 'handle']);

        $this->assertSame(1, Script::where('content_idea_id', $idea->id)->count());
    }

    public function test_a_second_handle_call_while_the_idea_is_still_processing_reuses_the_same_script_row(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $idea = ContentIdea::factory()->create([
            'status' => ContentIdeaStatus::Processing,
            'content_project_id' => ContentProject::factory()->create(['settings' => []]),
        ]);

        Script::factory()->create([
            'content_idea_id' => $idea->id,
            'status' => ScriptStatus::Processing,
            'content' => null,
        ]);

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'T', 'hook' => 'H', 'script' => 'Body', 'estimated_duration' => 55, 'cta' => 'Follow'])
            );
        });

        app()->call([new GenerateScriptJob($idea->id), 'handle']);

        $this->assertSame(1, Script::where('content_idea_id', $idea->id)->count());
        $this->assertSame(ScriptStatus::Completed, Script::where('content_idea_id', $idea->id)->sole()->status);
    }

    public function test_it_resolves_provider_and_model_from_the_projects_script_settings(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'T', 'hook' => 'H', 'script' => 'Body', 'estimated_duration' => 55, 'cta' => 'Follow'])
            );
        });

        $project = ContentProject::factory()->create([
            'settings' => [
                'ai' => [
                    'script' => ['provider' => 'fake_secondary', 'model' => 'override-model'],
                ],
            ],
        ]);

        $idea = ContentIdea::factory()->create([
            'status' => ContentIdeaStatus::Approved,
            'content_project_id' => $project->id,
        ]);

        app()->call([new GenerateScriptJob($idea->id), 'handle']);

        $script = Script::where('content_idea_id', $idea->id)->sole();
        $this->assertSame('fake_secondary', $script->provider);
        $this->assertSame('override-model', $script->model);
        $this->assertSame(ScriptStatus::Completed, $script->status);
    }

    public function test_failed_marks_the_script_failed_and_reverts_the_idea_to_approved(): void
    {
        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::Processing]);
        $script = Script::factory()->create([
            'content_idea_id' => $idea->id,
            'status' => ScriptStatus::Processing,
            'content' => null,
        ]);

        $job = new GenerateScriptJob($idea->id);
        $job->failed(new \RuntimeException('LLM unavailable'));

        $this->assertSame(ScriptStatus::Failed, $script->fresh()->status);
        $this->assertSame('LLM unavailable', $script->fresh()->metadata['error']);
        $this->assertSame(ContentIdeaStatus::Approved, $idea->fresh()->status);
    }
}
