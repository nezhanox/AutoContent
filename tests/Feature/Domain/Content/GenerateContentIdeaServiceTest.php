<?php

namespace Tests\Feature\Domain\Content;

use App\Domain\Content\Services\GenerateContentIdeaService;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateContentIdeaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_content_idea_from_the_llm_response(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'AI news roundup', 'topic' => 'ai news', 'score' => 82.5])
            );
        });

        $project = ContentProject::factory()->create(['settings' => []]);

        $service = $this->app->make(GenerateContentIdeaService::class);
        $idea = $service->generate($project, 'ai news');

        $this->assertDatabaseHas('content_ideas', [
            'id' => $idea->id,
            'content_project_id' => $project->id,
            'title' => 'AI news roundup',
            'topic' => 'ai news',
            'source' => 'ai_generated',
        ]);
        $this->assertSame(ContentIdeaStatus::New, $idea->fresh()->status);
        $this->assertSame(82.5, $idea->score);
    }

    public function test_it_throws_when_the_llm_response_is_not_valid_json(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith('not json');
        });

        $project = ContentProject::factory()->create(['settings' => []]);
        $service = $this->app->make(GenerateContentIdeaService::class);

        $this->expectException(\JsonException::class);

        $service->generate($project, 'ai news');
    }
}
