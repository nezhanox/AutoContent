<?php

namespace App\Domain\Source\Support;

use App\Models\Enums\SourceChannelMode;
use App\Models\SourceChannel;

final class ClipConstraints
{
    public function __construct(
        public readonly SourceChannelMode $mode,
        public readonly int $targetSeconds,
        public readonly int $toleranceSeconds,
        public readonly int $maxClips,
        public readonly int $minScore,
        public readonly int $minTailSeconds = 10,
    ) {}

    public static function fromChannel(SourceChannel $channel): self
    {
        return new self(
            mode: $channel->mode,
            targetSeconds: (int) $channel->target_seconds,
            toleranceSeconds: (int) $channel->tolerance_seconds,
            maxClips: (int) $channel->max_clips,
            minScore: (int) $channel->min_score,
        );
    }

    public function minSeconds(): int
    {
        return max(1, $this->targetSeconds - $this->toleranceSeconds);
    }

    public function maxSeconds(): int
    {
        return $this->targetSeconds + $this->toleranceSeconds;
    }
}
