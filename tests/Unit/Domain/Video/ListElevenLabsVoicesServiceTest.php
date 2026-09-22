<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Services\ListElevenLabsVoicesService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ListElevenLabsVoicesServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('tts.providers.elevenlabs.api_key', 'test-key');
        config()->set('tts.providers.elevenlabs.base_url', 'https://api.elevenlabs.io/v1');
    }

    public function test_it_returns_voice_id_to_label_options_from_the_api(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/voices' => Http::response([
                'voices' => [
                    ['voice_id' => 'abc123', 'name' => 'Did Vishchun - Carpathian Elder'],
                    ['voice_id' => 'def456', 'name' => 'George - Warm, Captivating Storyteller'],
                ],
            ], 200),
        ]);

        $options = (new ListElevenLabsVoicesService)->options();

        $this->assertSame([
            'abc123' => 'Did Vishchun - Carpathian Elder',
            'def456' => 'George - Warm, Captivating Storyteller',
        ], $options);
    }

    public function test_it_caches_the_result_and_does_not_request_again(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/voices' => Http::response([
                'voices' => [['voice_id' => 'abc123', 'name' => 'Did Vishchun']],
            ], 200),
        ]);

        $service = new ListElevenLabsVoicesService;
        $service->options();
        $service->options();

        Http::assertSentCount(1);
    }

    public function test_it_returns_an_empty_array_when_no_api_key_is_configured(): void
    {
        config()->set('tts.providers.elevenlabs.api_key', null);
        Http::fake();

        $options = (new ListElevenLabsVoicesService)->options();

        $this->assertSame([], $options);
        Http::assertNothingSent();
    }

    public function test_it_returns_an_empty_array_when_the_request_fails(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/voices' => Http::response('server error', 500),
        ]);

        $options = (new ListElevenLabsVoicesService)->options();

        $this->assertSame([], $options);
    }
}
