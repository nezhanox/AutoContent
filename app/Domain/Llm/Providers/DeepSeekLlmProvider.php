<?php

namespace App\Domain\Llm\Providers;

use App\Domain\Llm\LlmProviderInterface;
use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\LlmResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeepSeekLlmProvider implements LlmProviderInterface
{
    public function complete(LlmRequest $request): LlmResponse
    {
        $messages = $request->messages;

        // DeepSeek's Chat Completions API rejects OpenAI's `json_schema` response
        // format ("This response_format type is unavailable now"). It only supports
        // `json_object`, which enforces valid JSON syntax but not a specific shape,
        // so the schema is inlined into the prompt for the model to follow.
        if ($request->responseSchema !== null) {
            $messages[] = [
                'role' => 'system',
                'content' => sprintf(
                    "Respond with a single JSON object only (no markdown, no prose) that matches exactly this JSON schema:\n%s",
                    json_encode($request->responseSchema['schema'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
                ),
            ];
        }

        $payload = [
            'model' => $request->model ?? config('llm.default_model'),
            'messages' => $messages,
            'temperature' => $request->temperature,
        ];

        if ($request->maxTokens !== null) {
            $payload['max_tokens'] = $request->maxTokens;
        }

        if ($request->responseSchema !== null) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::withToken(config('llm.providers.deepseek.api_key'))
                ->baseUrl(config('llm.providers.deepseek.base_url'))
                ->timeout(60)
                ->retry(3, 500, when: fn ($exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()))
                ->post('/chat/completions', $payload);
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException(
                'DeepSeek request failed: '.$exception->getMessage(), previous: $exception
            );
        }

        $data = $response->json();
        $finishReason = $data['choices'][0]['finish_reason'] ?? null;

        return new LlmResponse(
            content: $data['choices'][0]['message']['content'] ?? '',
            provider: 'deepseek',
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
