<?php

namespace App\Domain\Llm\Providers;

use App\Domain\Llm\LlmProviderInterface;
use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\LlmResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $request->responseSchema['name'],
                    'schema' => $request->responseSchema['schema'],
                    'strict' => $request->responseSchema['strict'] ?? true,
                ],
            ];
        }

        try {
            $response = Http::withToken(config('llm.providers.openai.api_key'))
                ->baseUrl(config('llm.providers.openai.base_url'))
                ->timeout(60)
                ->retry(3, 500, when: fn ($exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()))
                ->post('/chat/completions', $payload);
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException(
                'OpenAI request failed: '.$exception->getMessage(), previous: $exception
            );
        }

        $data = $response->json();
        $finishReason = $data['choices'][0]['finish_reason'] ?? null;

        return new LlmResponse(
            content: $data['choices'][0]['message']['content'] ?? '',
            provider: 'openai',
            model: $data['model'] ?? $payload['model'],
            promptTokens: $data['usage']['prompt_tokens'] ?? 0,
            completionTokens: $data['usage']['completion_tokens'] ?? 0,
            metadata: [
                'finish_reason' => $this->normalizeFinishReason($finishReason),
                'provider_finish_reason' => $finishReason,
            ],
        );
    }

    private function normalizeFinishReason(?string $raw): string
    {
        return match ($raw) {
            'stop' => 'stop',
            'length' => 'length',
            default => 'error',
        };
    }
}
