<?php

namespace App\Domain\Video;

final class TranscriptionResult
{
    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $segments
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly array $segments,
        public readonly string $language,
        public readonly array $metadata = [],
    ) {}
}
