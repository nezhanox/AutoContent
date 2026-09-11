<?php

namespace Tests\Unit\Domain\Llm;

use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\Providers\FakeLlmProvider;
use PHPUnit\Framework\TestCase;

class FakeLlmProviderTest extends TestCase
{
    public function test_it_returns_the_configured_response(): void
    {
        $provider = new FakeLlmProvider;
        $provider->respondWith('{"title":"Hello"}', promptTokens: 42, completionTokens: 7);

        $response = $provider->complete(new LlmRequest(purpose: 'script', messages: [
            ['role' => 'user', 'content' => 'hi'],
        ]));

        $this->assertSame('{"title":"Hello"}', $response->content);
        $this->assertSame('fake', $response->provider);
        $this->assertSame(42, $response->promptTokens);
        $this->assertSame(7, $response->completionTokens);
    }

    public function test_it_defaults_to_a_generic_ok_response(): void
    {
        $response = (new FakeLlmProvider)->complete(new LlmRequest(purpose: 'idea', messages: []));

        $this->assertSame('{"ok":true}', $response->content);
    }
}
