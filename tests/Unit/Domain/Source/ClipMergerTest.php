<?php

namespace Tests\Unit\Domain\Source;

use App\Domain\Source\Support\Clip;
use App\Domain\Source\Support\ClipConstraints;
use App\Domain\Source\Support\ClipMerger;
use App\Models\Enums\SourceChannelMode;
use PHPUnit\Framework\TestCase;

class ClipMergerTest extends TestCase
{
    public function test_it_keeps_higher_scored_clip_of_overlapping_highlights(): void
    {
        $constraints = new ClipConstraints(SourceChannelMode::Highlights, 60, 15, 5, 6);
        $clips = [new Clip(0, 60, 'A', score: 5), new Clip(30, 90, 'B', score: 9)];

        $result = (new ClipMerger)->merge($clips, $constraints);

        $this->assertCount(1, $result);
        $this->assertSame(9, $result[0]->score);
    }

    public function test_it_limits_highlights_to_max_clips_and_sorts_by_start(): void
    {
        $constraints = new ClipConstraints(SourceChannelMode::Highlights, 60, 15, 2, 6);
        $clips = [
            new Clip(0, 60, 'A', score: 7),
            new Clip(100, 160, 'B', score: 9),
            new Clip(200, 260, 'C', score: 8),
        ];

        $result = (new ClipMerger)->merge($clips, $constraints);

        $this->assertSame(['B', 'C'], array_map(fn (Clip $c) => $c->title, $result));
    }

    public function test_it_keeps_earlier_clip_of_overlapping_fixed_clips(): void
    {
        $constraints = new ClipConstraints(SourceChannelMode::Fixed, 60, 15, 10, 0);
        $clips = [new Clip(50, 110, 'Late'), new Clip(0, 60, 'Early')];

        $result = (new ClipMerger)->merge($clips, $constraints);

        $this->assertCount(1, $result);
        $this->assertSame('Early', $result[0]->title);
    }
}
