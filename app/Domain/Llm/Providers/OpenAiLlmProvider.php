<?php

namespace App\Domain\Llm\Providers;

use App\Domain\Llm\LlmProviderInterface;
use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\LlmResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiLlmProvider implements LlmProviderInterface
{
    public function complete(LlmRequest $request): LlmResponse
    {
        $payload = [
            'model' => $request->model ?? config('llm.default_model'),
            'messages' => $request->messages,
            'temperature' => $request->temperature,
        ];

        if ($request->maxTokens !== null) {
            $payload['max_tokens'] = $request->maxTokens;
        }

        if ($request->responseSchema !== null) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::withToken(config('llm.providers.openai.api_key'))
            ->baseUrl(config('llm.providers.openai.base_url'))
            ->post('/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'OpenAI request failed: '.$response->status().' '.$response->body()
            );
        }

        $data = $response->json();

        return new LlmResponse(
            content: $data['choices'][0]['message']['content'] ?? '',
            provider: 'openai',
            model: $data['model'] ?? $payload['model'],
            promptTokens: $data['usage']['prompt_tokens'] ?? 0,
            completionTokens: $data['usage']['completion_tokens'] ?? 0,
        );
    }
}
