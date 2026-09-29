<?php

namespace Tests\Unit\Domain\Source;

use App\Domain\Source\Support\SubtitleSlicer;
use PHPUnit\Framework\TestCase;

final class SubtitleSlicerTest extends TestCase
{
    public function test_it_shifts_segment_start_time_within_clip(): void
    {
        $slicer = new SubtitleSlicer(maxWords: 6);
        $segments = [
            ['start' => 100.0, 'end' => 102.0, 'text' => 'Hi there'],
        ];

        $result = $slicer->slice($segments, clipStart: 95.0, clipEnd: 120.0);

        $this->assertCount(1, $result);
        $this->assertEqualsWithDelta(5.0, $result[0]['start'], 0.001);
        $this->assertEqualsWithDelta(7.0, $result[0]['end'], 0.001);
        $this->assertSame('Hi there', $result[0]['text']);
    }

    public function test_it_discards_segments_outside_clip(): void
    {
        $slicer = new SubtitleSlicer(maxWords: 6);
        $segments = [
            ['start' => 50.0, 'end' => 60.0, 'text' => 'Before clip'],
            ['start' => 100.0, 'end' => 102.0, 'text' => 'In clip'],
            ['start' => 150.0, 'end' => 160.0, 'text' => 'After clip'],
        ];

        $result = $slicer->slice($segments, clipStart: 95.0, clipEnd: 120.0);

        $this->assertCount(1, $result);
        $this->assertSame('In clip', $result[0]['text']);
    }

    public function test_it_chunks_long_segments_by_word_count(): void
    {
        $slicer = new SubtitleSlicer(maxWords: 6);
        $segments = [
            ['start' => 10.0, 'end' => 16.0, 'text' => 'one two three four five six seven eight'],
        ];

        $result = $slicer->slice($segments, clipStart: 10.0, clipEnd: 20.0);

        $this->assertCount(2, $result);
        $this->assertEqualsWithDelta(0.0, $result[0]['start'], 0.001);
        $this->assertEqualsWithDelta(4.5, $result[0]['end'], 0.001);
        $this->assertSame('one two three four five six', $result[0]['text']);
        $this->assertEqualsWithDelta(4.5, $result[1]['start'], 0.001);
        $this->assertEqualsWithDelta(6.0, $result[1]['end'], 0.001);
        $this->assertSame('seven eight', $result[1]['text']);
    }

    public function test_it_clips_segments_that_cross_clip_boundary(): void
    {
        $slicer = new SubtitleSlicer(maxWords: 6);
        $segments = [
            ['start' => 10.0, 'end' => 20.0, 'text' => 'This is a long text'],
        ];

        $result = $slicer->slice($segments, clipStart: 10.0, clipEnd: 15.0);

        $this->assertCount(1, $result);
        $this->assertEqualsWithDelta(0.0, $result[0]['start'], 0.001);
        $this->assertEqualsWithDelta(5.0, $result[0]['end'], 0.001);
    }
}
