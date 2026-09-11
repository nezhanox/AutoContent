<?php

namespace App\Domain\Llm;

final class LlmRequest
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function __construct(
        public readonly string $purpose,
        public readonly array $messages,
        public readonly ?string $responseSchema = null,
        public readonly ?string $model = null,
        public readonly float $temperature = 0.7,
        public readonly ?int $maxTokens = null,
    ) {}
}
