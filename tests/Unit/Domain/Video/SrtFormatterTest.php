<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Support\SrtFormatter;
use Tests\TestCase;

class SrtFormatterTest extends TestCase
{
    public function test_it_formats_a_single_segment(): void
    {
        $srt = SrtFormatter::format([
            ['start' => 0.0, 'end' => 2.4, 'text' => 'Hello world'],
        ]);

        $this->assertSame("1\n00:00:00,000 --> 00:00:02,400\nHello world\n", $srt);
    }

    public function test_it_formats_multiple_segments_separated_by_a_blank_line(): void
    {
        $srt = SrtFormatter::format([
            ['start' => 0.0, 'end' => 1.0, 'text' => 'One'],
            ['start' => 1.0, 'end' => 2.0, 'text' => 'Two'],
        ]);

        $this->assertSame(
            "1\n00:00:00,000 --> 00:00:01,000\nOne\n\n2\n00:00:01,000 --> 00:00:02,000\nTwo\n",
            $srt
        );
    }

    public function test_it_rolls_over_minutes_and_hours_correctly(): void
    {
        $srt = SrtFormatter::format([
            ['start' => 3661.5, 'end' => 3662.0, 'text' => 'One hour in'],
        ]);

        $this->assertSame("1\n01:01:01,500 --> 01:01:02,000\nOne hour in\n", $srt);
    }

    public function test_it_returns_an_empty_string_for_no_segments(): void
    {
        $this->assertSame('', SrtFormatter::format([]));
    }
}
