<?php

namespace Tests\Feature\Domain\Content;

use App\Domain\Content\Services\StartVideoGenerationService;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Jobs\GenerateScriptJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StartVideoGenerationServiceTest extends TestCase
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

        // 'settings' => [] avoids ContentProjectFactory's default ai.default.provider
        // outranking the 'fake' provider set via config() above.
        $project = ContentProject::factory()->create(['settings' => []]);

        $idea = app(StartVideoGenerationService::class)->generate($project, 'ai');

        $this->assertInstanceOf(ContentIdea::class, $idea);
        $this->assertSame(ContentIdeaStatus::Approved, $idea->fresh()->status);

        Queue::assertPushed(GenerateScriptJob::class, fn (GenerateScriptJob $job) => $job->contentIdeaId === $idea->id);
    }
}
