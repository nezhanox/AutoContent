<?php

namespace Tests\Support;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\LlmResponse;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Domain\Llm\ResolvedLlmTarget;
use App\Models\ContentProject;
use RuntimeException;

final class QueuedLlmManager implements LlmManagerInterface
{
    /** @var list<array<int, array{role: string, content: string}>> */
    public array $captured = [];

    /** @var list<string> */
    public array $purposes = [];

    /** @param list<string> $responses */
    public function __construct(private array $responses) {}

    public function resolve(?ContentProject $project, string $purpose, ?string $providerOverride = null, ?string $modelOverride = null): ResolvedLlmTarget
    {
        return new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');
    }

    public function complete(?ContentProject $project, string $purpose, array $messages, ?array $responseSchema = null, ?string $providerOverride = null, ?string $modelOverride = null, float $temperature = 0.7, ?int $maxTokens = null): LlmResponse
    {
        $this->captured[] = $messages;
        $this->purposes[] = $purpose;

        if ($this->responses === []) {
            throw new RuntimeException('QueuedLlmManager: no more queued responses.');
        }

        return new LlmResponse(array_shift($this->responses), 'fake', 'fake-model', 10, 10);
    }
}
