<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\TtsProviderInterface;
use App\Domain\Video\VoiceResult;
use App\Domain\Video\VoiceSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ElevenLabsTtsProvider implements TtsProviderInterface
{
    public function generate(string $text, VoiceSettings $settings): VoiceResult
    {
        $modelId = config('tts.providers.elevenlabs.model_id');

        try {
            $response = Http::withHeaders([
                'xi-api-key' => config('tts.providers.elevenlabs.api_key'),
            ])
                ->baseUrl(config('tts.providers.elevenlabs.base_url'))
                ->timeout(120)
                ->retry(3, 500, when: fn ($exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()))
                ->post("/text-to-speech/{$settings->voiceId}", [
                    'text' => $text,
                    'model_id' => $modelId,
                    'voice_settings' => [
                        'stability' => $settings->stability,
                        'similarity_boost' => $settings->similarityBoost,
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'ElevenLabs request failed: '.$exception->getMessage(), previous: $exception
            );
        } catch (RequestException $exception) {
            throw new RuntimeException(
                'ElevenLabs request failed: '.$this->errorMessage($exception->response->body()),
                previous: $exception,
            );
        }

        return new VoiceResult(
            audioContent: $response->body(),
            provider: 'elevenlabs',
            voice: $settings->voiceId,
            metadata: ['model_id' => $modelId],
        );
    }

    private function errorMessage(string $body): string
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! array_key_exists('detail', $decoded)) {
            return $body;
        }

        $detail = $decoded['detail'];

        if (is_array($detail)) {
            return $detail['message'] ?? json_encode($detail);
        }

        return (string) $detail;
    }
}
