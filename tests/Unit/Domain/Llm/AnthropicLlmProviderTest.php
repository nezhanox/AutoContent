<?php

namespace Tests\Unit\Domain\Llm;

use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\Providers\AnthropicLlmProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class AnthropicLlmProviderTest extends TestCase
{
    public function test_it_parses_a_successful_response(): void
    {
        config()->set('llm.providers.anthropic.api_key', 'test-key');
        config()->set('llm.providers.anthropic.base_url', 'https://api.anthropic.com/v1');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-haiku-4.5',
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => '{"title":"Hello"}']],
                'usage' => ['input_tokens' => 80, 'output_tokens' => 20],
            ], 200),
        ]);

        $provider = new AnthropicLlmProvider;
        $response = $provider->complete(new LlmRequest(
            purpose: 'quality_check',
            messages: [
                ['role' => 'system', 'content' => 'You are a QA reviewer.'],
                ['role' => 'user', 'content' => 'Review this script.'],
            ],
            model: 'claude-haiku-4.5',
        ));

        $this->assertSame('{"title":"Hello"}', $response->content);
        $this->assertSame('anthropic', $response->provider);
        $this->assertSame(80, $response->promptTokens);
        $this->assertSame(20, $response->completionTokens);
        $this->assertSame('stop', $response->metadata['finish_reason']);
        $this->assertSame('end_turn', $response->metadata['provider_finish_reason']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'test-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request['system'] === 'You are a QA reviewer.'
                && count($request['messages']) === 1;
        });
    }

    public function test_it_throws_on_a_failed_response(): void
    {
        config()->set('llm.providers.anthropic.api_key', 'test-key');
        config()->set('llm.providers.anthropic.base_url', 'https://api.anthropic.com/v1');

        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529),
        ]);

        $provider = new AnthropicLlmProvider;

        $this->expectException(\RuntimeException::class);

        $provider->complete(new LlmRequest(purpose: 'script', messages: [], model: 'claude-haiku-4.5'));
    }

    public function test_it_retries_on_server_errors_and_eventually_succeeds(): void
    {
        config()->set('llm.providers.anthropic.api_key', 'test-key');
        config()->set('llm.providers.anthropic.base_url', 'https://api.anthropic.com/v1');

        Sleep::fake();

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['error' => 'overloaded'], 529)
                ->push(['error' => 'overloaded'], 529)
                ->push([
                    'model' => 'claude-haiku-4.5',
                    'stop_reason' => 'end_turn',
                    'content' => [['type' => 'text', 'text' => '{"ok":true}']],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ], 200),
        ]);

        $provider = new AnthropicLlmProvider;
        $response = $provider->complete(new LlmRequest(purpose: 'script', messages: [], model: 'claude-haiku-4.5'));

        $this->assertSame('{"ok":true}', $response->content);
        Http::assertSentCount(3);
    }

    public function test_it_extracts_structured_output_from_forced_tool_use(): void
    {
        config()->set('llm.providers.anthropic.api_key', 'test-key');
        config()->set('llm.providers.anthropic.base_url', 'https://api.anthropic.com/v1');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-opus-4',
                'stop_reason' => 'tool_use',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'video_script',
                    'input' => [
                        'title' => 'Hi', 'hook' => 'H', 'script' => 'S',
                        'estimated_duration' => 60, 'cta' => 'C',
                    ],
                ]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 40],
            ], 200),
        ]);

        $provider = new AnthropicLlmProvider;
        $response = $provider->complete(new LlmRequest(
            purpose: 'script',
            messages: [['role' => 'user', 'content' => 'hi']],
            responseSchema: [
                'name' => 'video_script',
                'schema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
            ],
            model: 'claude-opus-4',
        ));

        $this->assertSame(
            ['title' => 'Hi', 'hook' => 'H', 'script' => 'S', 'estimated_duration' => 60, 'cta' => 'C'],
            json_decode($response->content, true)
        );
        $this->assertSame('stop', $response->metadata['finish_reason']);
        $this->assertSame('tool_use', $response->metadata['provider_finish_reason']);

        Http::assertSent(function ($request) {
            return $request['tool_choice'] === ['type' => 'tool', 'name' => 'video_script']
                && $request['tools'][0]['name'] === 'video_script';
        });
    }
}
