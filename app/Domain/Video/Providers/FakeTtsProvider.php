<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\TtsProviderInterface;
use App\Domain\Video\VoiceResult;
use App\Domain\Video\VoiceSettings;

class FakeTtsProvider implements TtsProviderInterface
{
    private string $audioContent = 'fake-audio-bytes';

    private string $provider = 'fake';

    /** @var array<string, mixed> */
    private array $metadata = [];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function respondWith(string $audioContent, string $provider = 'fake', array $metadata = []): static
    {
        $this->audioContent = $audioContent;
        $this->provider = $provider;
        $this->metadata = $metadata;

        return $this;
    }

    public function generate(string $text, VoiceSettings $settings): VoiceResult
    {
        return new VoiceResult(
            audioContent: $this->audioContent,
            provider: $this->provider,
            voice: $settings->voiceId,
            metadata: $this->metadata,
        );
    }
}
