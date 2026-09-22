<?php

namespace Tests\Feature\Domain\Video;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\LlmResponse;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Domain\Llm\ResolvedLlmTarget;
use App\Domain\Video\Exceptions\SceneGenerationFailedException;
use App\Domain\Video\Services\GenerateScenesService;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateScenesServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_tells_the_llm_the_project_niche_and_to_prefer_classical_art_queries_for_historical_topics(): void
    {
        $project = ContentProject::factory()->create(['niche' => 'stoicism']);
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);
        $script = Script::factory()->create(['content_idea_id' => $idea->id]);
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->capturingLlmManager([
            '{"scenes":[{"type":"hook","duration":3,"visual_query":"marble statue of a stoic philosopher","text":"Hi"}]}',
        ]);

        $service = new GenerateScenesService($manager);
        $service->generate($script, $target);

        $system = $manager->capturedMessages[0]['content'];

        $this->assertStringContainsString('stoicism', $system);
        $this->assertStringContainsString('Marcus Aurelius', $system);
        $this->assertStringContainsString('short keyword phrase', $system);
    }

    public function test_it_returns_the_parsed_scenes_on_a_valid_first_response(): void
    {
        $script = Script::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager([
            '{"scenes":[{"type":"hook","duration":3,"visual_query":"a laptop","text":"Hi"},{"type":"cta","duration":2,"visual_query":null,"text":"Follow"}]}',
        ]);

        $service = new GenerateScenesService($manager);
        $result = $service->generate($script, $target);

        $this->assertSame([
            ['type' => 'hook', 'duration' => 3, 'visual_query' => 'a laptop', 'text' => 'Hi'],
            ['type' => 'cta', 'duration' => 2, 'visual_query' => null, 'text' => 'Follow'],
        ], $result);
    }

    public function test_it_repairs_after_one_invalid_response(): void
    {
        $script = Script::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager([
            'not json at all',
            '{"scenes":[{"type":"hook","duration":3,"visual_query":null,"text":"Hi"}]}',
        ]);

        $service = new GenerateScenesService($manager);
        $result = $service->generate($script, $target);

        $this->assertCount(1, $result);
        $this->assertSame('hook', $result[0]['type']);
    }

    public function test_it_throws_after_exhausting_repair_attempts(): void
    {
        $script = Script::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager([
            'not json',
            'still not json',
            'nope',
        ]);

        $service = new GenerateScenesService($manager);

        $this->expectException(SceneGenerationFailedException::class);

        $service->generate($script, $target);
    }

    public function test_it_rejects_a_scene_with_an_invalid_type(): void
    {
        $script = Script::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $invalidType = '{"scenes":[{"type":"not-a-real-type","duration":3,"visual_query":null,"text":"Hi"}]}';

        $manager = $this->queuedLlmManager([$invalidType, $invalidType, $invalidType]);

        $service = new GenerateScenesService($manager);

        $this->expectException(SceneGenerationFailedException::class);

        $service->generate($script, $target);
    }

    public function test_it_rejects_an_empty_scenes_array(): void
    {
        $script = Script::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager(['{"scenes":[]}', '{"scenes":[]}', '{"scenes":[]}']);

        $service = new GenerateScenesService($manager);

        $this->expectException(SceneGenerationFailedException::class);

        $service->generate($script, $target);
    }

    public function test_it_rejects_a_non_positive_duration(): void
    {
        $script = Script::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $invalidDuration = '{"scenes":[{"type":"hook","duration":0,"visual_query":null,"text":"Hi"}]}';

        $manager = $this->queuedLlmManager([$invalidDuration, $invalidDuration, $invalidDuration]);

        $service = new GenerateScenesService($manager);

        $this->expectException(SceneGenerationFailedException::class);

        $service->generate($script, $target);
    }

    public function test_it_rejects_a_visual_query_longer_than_255_characters(): void
    {
        $script = Script::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $tooLong = json_encode(['scenes' => [[
            'type' => 'hook',
            'duration' => 3,
            'visual_query' => str_repeat('a', 256),
            'text' => 'Hi',
        ]]]);

        $manager = $this->queuedLlmManager([$tooLong, $tooLong, $tooLong]);

        $service = new GenerateScenesService($manager);

        $this->expectException(SceneGenerationFailedException::class);

        $service->generate($script, $target);
    }

    /**
     * @param  array<int, string>  $responses
     */
    private function capturingLlmManager(array $responses): LlmManagerInterface
    {
        return new class($responses) implements LlmManagerInterface
        {
            private int $index = 0;

            /** @var array<int, array{role: string, content: string}> */
            public array $capturedMessages = [];

            /** @param array<int, string> $responses */
            public function __construct(private array $responses) {}

            public function resolve(?ContentProject $project, string $purpose, ?string $providerOverride = null, ?string $modelOverride = null): ResolvedLlmTarget
            {
                throw new \LogicException('Not used in this test.');
            }

            public function complete(?ContentProject $project, string $purpose, array $messages, ?array $responseSchema = null, ?string $providerOverride = null, ?string $modelOverride = null, float $temperature = 0.7, ?int $maxTokens = null): LlmResponse
            {
                $this->capturedMessages = $messages;
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
