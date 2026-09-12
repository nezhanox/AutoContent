<?php

namespace Tests\Unit\Domain\Llm;

use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\Providers\OpenAiLlmProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
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
                    ['message' => ['content' => '{"title":"Hello"}'], 'finish_reason' => 'stop'],
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
        $this->assertSame('stop', $response->metadata['finish_reason']);
        $this->assertSame('stop', $response->metadata['provider_finish_reason']);

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

    public function test_it_does_not_retry_on_client_errors(): void
    {
        config()->set('llm.providers.openai.api_key', 'test-key');
        config()->set('llm.providers.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'api.openai.com/*' => Http::response(['error' => 'bad request'], 400),
        ]);

        $provider = new OpenAiLlmProvider;

        try {
            $provider->complete(new LlmRequest(purpose: 'script', messages: [], model: 'gpt-4o-mini'));
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            // expected
        }

        Http::assertSentCount(1);
    }

    public function test_it_retries_on_server_errors_and_eventually_succeeds(): void
    {
        config()->set('llm.providers.openai.api_key', 'test-key');
        config()->set('llm.providers.openai.base_url', 'https://api.openai.com/v1');

        Sleep::fake();

        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push(['error' => 'server error'], 500)
                ->push(['error' => 'server error'], 500)
                ->push([
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => ['content' => '{"ok":true}'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ], 200),
        ]);

        $provider = new OpenAiLlmProvider;
        $response = $provider->complete(new LlmRequest(purpose: 'script', messages: [], model: 'gpt-4o-mini'));

        $this->assertSame('{"ok":true}', $response->content);
        Http::assertSentCount(3);
    }

    public function test_it_requests_structured_output_via_json_schema(): void
    {
        config()->set('llm.providers.openai.api_key', 'test-key');
        config()->set('llm.providers.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => '{"title":"Hi"}'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $provider = new OpenAiLlmProvider;
        $response = $provider->complete(new LlmRequest(
            purpose: 'script',
            messages: [['role' => 'user', 'content' => 'hi']],
            responseSchema: [
                'name' => 'video_script',
                'schema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
            ],
            model: 'gpt-4o-mini',
        ));

        $this->assertSame('{"title":"Hi"}', $response->content);

        Http::assertSent(function ($request) {
            return $request['response_format']['type'] === 'json_schema'
                && $request['response_format']['json_schema']['name'] === 'video_script'
                && $request['response_format']['json_schema']['strict'] === true;
        });
    }
}
