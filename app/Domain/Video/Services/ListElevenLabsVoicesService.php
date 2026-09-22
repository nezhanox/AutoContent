<?php

namespace App\Domain\Video\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ListElevenLabsVoicesService
{
    private const CACHE_KEY = 'elevenlabs.voices.options';

    private const CACHE_TTL_SECONDS = 3600;

    /**
     * @return array<string, string> voice_id => display label
     */
    public function options(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $apiKey = config('tts.providers.elevenlabs.api_key');

            if (blank($apiKey)) {
                return [];
            }

            try {
                $response = Http::withHeaders(['xi-api-key' => $apiKey])
                    ->baseUrl(config('tts.providers.elevenlabs.base_url'))
                    ->timeout(10)
                    ->get('/voices');
            } catch (Throwable $exception) {
                Log::channel('video')->warning('elevenlabs voice list request failed', ['error' => $exception->getMessage()]);

                return [];
            }

            if ($response->failed()) {
                Log::channel('video')->warning('elevenlabs voice list request failed', ['status' => $response->status()]);

                return [];
            }

            return collect($response->json('voices') ?? [])
                ->mapWithKeys(fn (array $voice): array => [$voice['voice_id'] => $voice['name'] ?? $voice['voice_id']])
                ->all();
        });
    }
}
