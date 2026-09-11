<?php

namespace App\Domain\Llm;

use App\Models\ContentProject;

interface LlmManagerInterface
{
    public function resolve(
        ?ContentProject $project,
        string $purpose,
        ?string $providerOverride = null,
        ?string $modelOverride = null,
    ): ResolvedLlmTarget;

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function complete(
        ?ContentProject $project,
        string $purpose,
        array $messages,
        ?string $responseSchema = null,
        ?string $providerOverride = null,
        ?string $modelOverride = null,
        float $temperature = 0.7,
        ?int $maxTokens = null,
    ): LlmResponse;
}
