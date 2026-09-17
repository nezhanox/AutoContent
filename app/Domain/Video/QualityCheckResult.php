<?php

namespace App\Domain\Video;

final class QualityCheckResult
{
    /**
     * @param  array<string, bool>  $checks
     * @param  array<int, string>  $notes
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $passed,
        public readonly array $checks,
        public readonly array $notes = [],
        public readonly array $metadata = [],
    ) {}
}
