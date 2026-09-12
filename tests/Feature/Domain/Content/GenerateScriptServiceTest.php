<?php

namespace Tests\Feature\Domain\Content;

use App\Domain\Content\Exceptions\ScriptGenerationFailedException;
use App\Domain\Content\Services\GenerateScriptService;
use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\LlmResponse;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Domain\Llm\ResolvedLlmTarget;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateScriptServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_parsed_script_on_a_valid_first_response(): void
    {
        $idea = ContentIdea::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager([
            '{"title":"T","hook":"H","script":"S","estimated_duration":42,"cta":"C"}',
        ]);

        $service = new GenerateScriptService($manager);
        $result = $service->generate($idea, $target);

        $this->assertSame([
            'title' => 'T', 'hook' => 'H', 'script' => 'S', 'estimated_duration' => 42, 'cta' => 'C',
        ], $result);
    }

    public function test_it_repairs_after_one_invalid_response(): void
    {
        $idea = ContentIdea::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager([
            'not json at all',
            '{"title":"T","hook":"H","script":"S","estimated_duration":42,"cta":"C"}',
        ]);

        $service = new GenerateScriptService($manager);
        $result = $service->generate($idea, $target);

        $this->assertSame(42, $result['estimated_duration']);
    }

    public function test_it_throws_after_exhausting_repair_attempts(): void
    {
        $idea = ContentIdea::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager([
            'not json',
            'still not json',
            'nope',
        ]);

        $service = new GenerateScriptService($manager);

        $this->expectException(ScriptGenerationFailedException::class);

        $service->generate($idea, $target);
    }

    public function test_it_rejects_a_response_missing_a_required_field(): void
    {
        $idea = ContentIdea::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager([
            '{"title":"T","hook":"H","script":"S","cta":"C"}',
            '{"title":"T","hook":"H","script":"S","cta":"C"}',
            '{"title":"T","hook":"H","script":"S","cta":"C"}',
        ]);

        $service = new GenerateScriptService($manager);

        $this->expectException(ScriptGenerationFailedException::class);

        $service->generate($idea, $target);
    }

    /**
     * @param  array<int, string>  $responses
     */
    private function queuedLlmManager(array $responses): LlmManagerInterface
    {
        return new class($responses) implements LlmManagerInterface
        {
            private int $index = 0;

            /** @param array<int, string> $responses */
            public function __construct(private array $responses) {}

            public function resolve(?ContentProject $project, string $purpose, ?string $providerOverride = null, ?string $modelOverride = null): ResolvedLlmTarget
            {
                throw new \LogicException('Not used in this test.');
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
        };
    }
}
