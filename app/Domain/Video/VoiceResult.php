<?php

namespace App\Domain\Video;

final class VoiceResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $audioContent,
        public readonly string $provider,
        public readonly string $voice,
        public readonly array $metadata = [],
    ) {}
}
