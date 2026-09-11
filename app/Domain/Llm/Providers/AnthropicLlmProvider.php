<?php

namespace App\Domain\Llm\Providers;

use App\Domain\Llm\LlmProviderInterface;
use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\LlmResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicLlmProvider implements LlmProviderInterface
{
    public function complete(LlmRequest $request): LlmResponse
    {
        $systemMessages = array_values(array_filter(
            $request->messages,
            fn (array $message) => $message['role'] === 'system'
        ));

        $userMessages = array_values(array_filter(
            $request->messages,
            fn (array $message) => $message['role'] !== 'system'
        ));

        $payload = [
            'model' => $request->model ?? config('llm.default_model'),
            'messages' => $userMessages,
            'max_tokens' => $request->maxTokens ?? 1024,
            'temperature' => $request->temperature,
        ];

        if ($systemMessages !== []) {
            $payload['system'] = implode("\n\n", array_column($systemMessages, 'content'));
        }

        $response = Http::withHeaders([
            'x-api-key' => config('llm.providers.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->baseUrl(config('llm.providers.anthropic.base_url'))
            ->post('/messages', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'Anthropic request failed: '.$response->status().' '.$response->body()
            );
        }

        $data = $response->json();

        return new LlmResponse(
            content: $data['content'][0]['text'] ?? '',
            provider: 'anthropic',
            model: $data['model'] ?? $payload['model'],
            promptTokens: $data['usage']['input_tokens'] ?? 0,
            completionTokens: $data['usage']['output_tokens'] ?? 0,
        );
    }
}
