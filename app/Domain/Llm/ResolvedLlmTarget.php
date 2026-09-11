<?php

namespace App\Domain\Llm;

final class ResolvedLlmTarget
{
    public function __construct(
        public readonly LlmProviderInterface $provider,
        public readonly string $providerName,
        public readonly string $model,
    ) {}
}
