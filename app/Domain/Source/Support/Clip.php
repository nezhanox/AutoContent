<?php

namespace App\Domain\Source\Support;

final class Clip
{
    public function __construct(
        public readonly float $start,
        public readonly float $end,
        public readonly string $title,
        public readonly ?string $hook = null,
        public readonly ?int $score = null,
        public readonly ?string $reason = null,
    ) {}

    public function duration(): float
    {
        return $this->end - $this->start;
    }
}
