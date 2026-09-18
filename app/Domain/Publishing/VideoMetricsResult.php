<?php

namespace App\Domain\Publishing;

final class VideoMetricsResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $views,
        public readonly int $likes,
        public readonly int $comments,
        public readonly int $shares,
        public readonly ?int $saves = null,
        public readonly ?int $watchTime = null,
        public readonly ?float $completionRate = null,
        public readonly ?int $followersGained = null,
        public readonly array $metadata = [],
    ) {}
}
