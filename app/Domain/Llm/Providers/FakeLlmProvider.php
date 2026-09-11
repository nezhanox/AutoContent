<?php

namespace App\Domain\Llm\Providers;

use App\Domain\Llm\LlmProviderInterface;
use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\LlmResponse;

class FakeLlmProvider implements LlmProviderInterface
{
    private string $content = '{"ok":true}';

    private int $promptTokens = 10;

    private int $completionTokens = 10;

    public function respondWith(string $content, int $promptTokens = 10, int $completionTokens = 10): static
    {
        $this->content = $content;
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;

        return $this;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        return new LlmResponse(
            content: $this->content,
            provider: 'fake',
            model: $request->model ?? 'fake-model',
            promptTokens: $this->promptTokens,
            completionTokens: $this->completionTokens,
        );
    }
}
