<?php

namespace Tests\Feature\Console;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Jobs\GenerateScriptJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerateContentIdeaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_approved_idea_and_dispatches_script_generation(): void
    {
        Queue::fake();

        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'T', 'topic' => 'ai', 'score' => 50])
            );
        });

        $project = ContentProject::factory()->create(['settings' => []]);

        $exitCode = Artisan::call('content-idea:generate', [
            'project' => $project->id,
            'topic' => 'ai',
        ]);

        $this->assertSame(0, $exitCode);

        $idea = ContentIdea::where('content_project_id', $project->id)->sole();
        $this->assertSame(ContentIdeaStatus::Approved, $idea->status);

        Queue::assertPushed(GenerateScriptJob::class, fn (GenerateScriptJob $job) => $job->contentIdeaId === $idea->id);
    }

    public function test_it_fails_for_an_unknown_project(): void
    {
        $exitCode = Artisan::call('content-idea:generate', [
            'project' => 999999,
            'topic' => 'ai',
        ]);

        $this->assertSame(1, $exitCode);
    }
}
