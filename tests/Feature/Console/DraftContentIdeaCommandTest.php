<?php

namespace Tests\Feature\Console;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\LlmResponse;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Domain\Llm\ResolvedLlmTarget;
use App\Models\ContentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DraftContentIdeaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_previews_script_and_scenes_without_persisting_anything(): void
    {
        $project = ContentProject::factory()->create();

        $this->app->bind(LlmManagerInterface::class, fn () => new class implements LlmManagerInterface
        {
            private int $index = 0;

            /** @var array<int, string> */
            private array $responses = [
                '{"title":"T","hook":"H","script":"S","estimated_duration":42,"cta":"C"}',
                '{"scenes":[{"type":"broll","duration":5,"visual_query":"stoic bust","text":"S"}]}',
            ];

            public function resolve(?ContentProject $project, string $purpose, ?string $providerOverride = null, ?string $modelOverride = null): ResolvedLlmTarget
            {
                return new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');
            }

            public function complete(?ContentProject $project, string $purpose, array $messages, ?array $responseSchema = null, ?string $providerOverride = null, ?string $modelOverride = null, float $temperature = 0.7, ?int $maxTokens = null): LlmResponse
            {
                $content = $this->responses[$this->index] ?? end($this->responses);
                $this->index++;

                return new LlmResponse(
                    content: $content,
                    provider: 'fake',
                    model: 'fake-model',
                    promptTokens: 10,
                    completionTokens: 5,
                );
            }
        });

        $exitCode = Artisan::call('content-idea:draft', [
            'project' => $project->id,
            'title' => 'Stoic Mornings',
            'topic' => 'how stoics started their day',
        ]);

        $this->assertSame(0, $exitCode);

        $output = json_decode(Artisan::output(), true);

        $this->assertSame('T', $output['script']['title']);
        $this->assertSame('broll', $output['scenes'][0]['type']);
        $this->assertSame('stoic bust', $output['scenes'][0]['visual_query']);

        $this->assertDatabaseCount('content_ideas', 0);
        $this->assertDatabaseCount('scripts', 0);
        $this->assertDatabaseCount('videos', 0);
        $this->assertDatabaseCount('video_scenes', 0);
    }

    public function test_it_fails_for_an_unknown_project(): void
    {
        $exitCode = Artisan::call('content-idea:draft', [
            'project' => 999999,
            'title' => 'T',
            'topic' => 'x',
        ]);

        $this->assertSame(1, $exitCode);
    }
}
