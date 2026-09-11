<?php

namespace App\Domain\Llm;

final class LlmResponse
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $content,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly array $metadata = [],
    ) {}
}
