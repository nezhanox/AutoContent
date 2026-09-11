<?php

namespace App\Domain\Llm;

interface LlmProviderInterface
{
    public function complete(LlmRequest $request): LlmResponse;
}
