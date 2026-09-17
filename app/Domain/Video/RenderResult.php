<?php

namespace App\Domain\Video;

final class RenderResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $path,
        public readonly float $duration,
        public readonly int $width,
        public readonly int $height,
        public readonly array $metadata = [],
    ) {}
}
