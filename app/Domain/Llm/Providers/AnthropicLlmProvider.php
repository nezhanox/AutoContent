<?php

namespace App\Domain\Llm\Providers;

use App\Domain\Llm\LlmProviderInterface;
use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\LlmResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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

        if ($request->responseSchema !== null) {
            $payload['tools'] = [[
                'name' => $request->responseSchema['name'],
                'input_schema' => $request->responseSchema['schema'],
            ]];
            $payload['tool_choice'] = ['type' => 'tool', 'name' => $request->responseSchema['name']];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => config('llm.providers.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
            ])
                ->baseUrl(config('llm.providers.anthropic.base_url'))
                ->timeout(60)
                ->retry(3, 500, when: fn ($exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()))
                ->post('/messages', $payload);
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException(
                'Anthropic request failed: '.$exception->getMessage(), previous: $exception
            );
        }

        $data = $response->json();
        $stopReason = $data['stop_reason'] ?? null;

        return new LlmResponse(
            content: $this->extractContent($data['content'] ?? [], $request->responseSchema !== null),
            provider: 'anthropic',
            model: $data['model'] ?? $payload['model'],
            promptTokens: $data['usage']['input_tokens'] ?? 0,
            completionTokens: $data['usage']['output_tokens'] ?? 0,
            metadata: [
                'finish_reason' => $this->normalizeFinishReason($stopReason),
                'provider_finish_reason' => $stopReason,
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function extractContent(array $blocks, bool $expectsToolUse): string
    {
        if ($expectsToolUse) {
            foreach ($blocks as $block) {
                if (($block['type'] ?? null) === 'tool_use') {
                    return json_encode($block['input'] ?? [], JSON_THROW_ON_ERROR);
                }
            }

            return '';
        }

        return $blocks[0]['text'] ?? '';
    }

    private function normalizeFinishReason(?string $raw): string
    {
        return match ($raw) {
            'end_turn', 'tool_use', 'stop_sequence' => 'stop',
            'max_tokens' => 'length',
            default => 'error',
        };
    }
}
