<?php

namespace App\Domain\Publishing;

final class PublishResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $externalPostId,
        public readonly array $metadata = [],
    ) {}
}
