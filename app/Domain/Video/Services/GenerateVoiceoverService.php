<?php

namespace App\Domain\Video\Services;

use App\Domain\Video\AudioProbeInterface;
use App\Domain\Video\TtsProviderInterface;
use App\Domain\Video\VoiceSettings;
use App\Models\Video;
use InvalidArgumentException;

final class GenerateVoiceoverService
{
    public function __construct(
        private readonly TtsProviderInterface $ttsProvider,
        private readonly AudioProbeInterface $audioProbe,
    ) {}

    /**
     * @return array{text: string, audio: string, provider: string, voice: string, duration: float, metadata: array<string, mixed>}
     */
    public function generate(Video $video): array
    {
        $text = $this->buildText($video);
        $settings = $this->resolveVoiceSettings($video);
        $result = $this->ttsProvider->generate($text, $settings);

        return [
            'text' => $text,
            'audio' => $result->audioContent,
            'provider' => $result->provider,
            'voice' => $result->voice,
            'duration' => $this->audioProbe->duration($result->audioContent),
            'metadata' => $result->metadata,
        ];
    }

    private function buildText(Video $video): string
    {
        return $video->scenes->pluck('text')->implode(' ');
    }

    private function resolveVoiceSettings(Video $video): VoiceSettings
    {
        $voiceId = $video->contentProject->settings['tts']['voice'] ?? config('tts.default_voice');

        if (! is_string($voiceId) || $voiceId === '') {
            throw new InvalidArgumentException(
                'No TTS voice configured for this video (set ContentProject.settings[tts][voice] or ELEVENLABS_DEFAULT_VOICE).'
            );
        }

        return new VoiceSettings(voiceId: $voiceId);
    }
}
