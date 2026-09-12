<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Providers\ElevenLabsTtsProvider;
use App\Domain\Video\VoiceSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ElevenLabsTtsProviderTest extends TestCase
{
    private function configure(): void
    {
        config()->set('tts.providers.elevenlabs.api_key', 'test-key');
        config()->set('tts.providers.elevenlabs.base_url', 'https://api.elevenlabs.io/v1');
        config()->set('tts.providers.elevenlabs.model_id', 'eleven_multilingual_v2');
    }

    public function test_it_returns_raw_audio_bytes_on_a_successful_response(): void
    {
        $this->configure();

        Http::fake([
            'api.elevenlabs.io/*' => Http::response('raw-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $provider = new ElevenLabsTtsProvider;
        $result = $provider->generate('Hello world', new VoiceSettings(voiceId: 'adam'));

        $this->assertSame('raw-mp3-bytes', $result->audioContent);
        $this->assertSame('elevenlabs', $result->provider);
        $this->assertSame('adam', $result->voice);
        $this->assertSame('eleven_multilingual_v2', $result->metadata['model_id']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/adam'
                && $request->hasHeader('xi-api-key', 'test-key')
                && ! $request->hasHeader('Authorization')
                && $request['text'] === 'Hello world'
                && $request['model_id'] === 'eleven_multilingual_v2'
                && $request['voice_settings'] === ['stability' => 0.5, 'similarity_boost' => 0.75];
        });
    }

    public function test_it_throws_with_the_elevenlabs_error_detail_on_a_client_error(): void
    {
        $this->configure();

        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => ['message' => 'Invalid voice_id']], 400),
        ]);

        $provider = new ElevenLabsTtsProvider;

        try {
            $provider->generate('Hello', new VoiceSettings(voiceId: 'bad-voice'));
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Invalid voice_id', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_it_falls_back_to_the_raw_body_when_the_error_is_not_json(): void
    {
        $this->configure();

        Http::fake([
            'api.elevenlabs.io/*' => Http::response('upstream gateway timeout', 400),
        ]);

        $provider = new ElevenLabsTtsProvider;

        try {
            $provider->generate('Hello', new VoiceSettings(voiceId: 'adam'));
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('upstream gateway timeout', $exception->getMessage());
        }
    }

    public function test_it_retries_on_server_errors_and_eventually_succeeds(): void
    {
        $this->configure();

        Sleep::fake();

        Http::fake([
            'api.elevenlabs.io/*' => Http::sequence()
                ->push(['detail' => 'overloaded'], 503)
                ->push(['detail' => 'overloaded'], 503)
                ->push('raw-mp3-bytes', 200),
        ]);

        $provider = new ElevenLabsTtsProvider;
        $result = $provider->generate('Hello', new VoiceSettings(voiceId: 'adam'));

        $this->assertSame('raw-mp3-bytes', $result->audioContent);
        Http::assertSentCount(3);
    }

    public function test_it_does_not_retry_on_a_client_error(): void
    {
        $this->configure();

        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => 'bad request'], 400),
        ]);

        $provider = new ElevenLabsTtsProvider;

        try {
            $provider->generate('Hello', new VoiceSettings(voiceId: 'adam'));
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException) {
            // expected
        }

        Http::assertSentCount(1);
    }
}
