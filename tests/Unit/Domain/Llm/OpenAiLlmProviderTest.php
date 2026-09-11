<?php

namespace Tests\Unit\Domain\Llm;

use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\Providers\OpenAiLlmProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiLlmProviderTest extends TestCase
{
    public function test_it_parses_a_successful_response(): void
    {
        config()->set('llm.providers.openai.api_key', 'test-key');
        config()->set('llm.providers.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [
                    ['message' => ['content' => '{"title":"Hello"}']],
                ],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
            ], 200),
        ]);

        $provider = new OpenAiLlmProvider;
        $response = $provider->complete(new LlmRequest(
            purpose: 'script',
            messages: [['role' => 'user', 'content' => 'hi']],
            model: 'gpt-4o-mini',
        ));

        $this->assertSame('{"title":"Hello"}', $response->content);
        $this->assertSame('openai', $response->provider);
        $this->assertSame(120, $response->promptTokens);
        $this->assertSame(30, $response->completionTokens);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && $request['model'] === 'gpt-4o-mini'
                && $request->hasHeader('Authorization', 'Bearer test-key');
        });
    }

    public function test_it_throws_on_a_failed_response(): void
    {
        config()->set('llm.providers.openai.api_key', 'test-key');
        config()->set('llm.providers.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'api.openai.com/*' => Http::response(['error' => 'rate limited'], 429),
        ]);

        $provider = new OpenAiLlmProvider;

        $this->expectException(\RuntimeException::class);

        $provider->complete(new LlmRequest(purpose: 'script', messages: [], model: 'gpt-4o-mini'));
    }
}
