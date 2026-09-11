<?php

namespace Tests\Unit\Domain\Llm;

use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\Providers\AnthropicLlmProvider;
use Illuminate\Support\Facades\Http;
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
}
