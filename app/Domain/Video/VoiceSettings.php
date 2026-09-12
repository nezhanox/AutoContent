<?php

namespace App\Domain\Video;

final class VoiceSettings
{
    public function __construct(
        public readonly string $voiceId,
        public readonly float $stability = 0.5,
        public readonly float $similarityBoost = 0.75,
    ) {}
}
