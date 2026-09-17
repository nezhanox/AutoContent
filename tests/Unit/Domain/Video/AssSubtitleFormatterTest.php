<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Support\AssSubtitleFormatter;
use Tests\TestCase;

class AssSubtitleFormatterTest extends TestCase
{
    private function style(array $overrides = []): array
    {
        return array_merge([
            'width' => 1080,
            'height' => 1920,
            'font' => 'DejaVu Sans',
            'font_size' => 64,
            'position' => 'bottom',
            'margin_v' => 120,
            'margin_h' => 60,
            'primary_colour' => '&H00FFFFFF',
            'outline_colour' => '&H00000000',
        ], $overrides);
    }

    public function test_it_writes_the_script_info_and_style_header(): void
    {
        $ass = AssSubtitleFormatter::format([], $this->style());

        $this->assertStringContainsString('[Script Info]', $ass);
        $this->assertStringContainsString('PlayResX: 1080', $ass);
        $this->assertStringContainsString('PlayResY: 1920', $ass);
        $this->assertStringContainsString(
            'Style: Default,DejaVu Sans,64,&H00FFFFFF,&H00000000,2,60,60,120',
            $ass
        );
    }

    public function test_bottom_position_maps_to_alignment_two(): void
    {
        $ass = AssSubtitleFormatter::format([], $this->style(['position' => 'bottom']));
        $this->assertStringContainsString(',2,60,60,120', $ass);
    }

    public function test_top_position_maps_to_alignment_eight(): void
    {
        $ass = AssSubtitleFormatter::format([], $this->style(['position' => 'top']));
        $this->assertStringContainsString(',8,60,60,120', $ass);
    }

    public function test_middle_position_maps_to_alignment_five(): void
    {
        $ass = AssSubtitleFormatter::format([], $this->style(['position' => 'middle']));
        $this->assertStringContainsString(',5,60,60,120', $ass);
    }

    public function test_empty_segments_produce_a_valid_header_with_no_dialogue_lines(): void
    {
        $ass = AssSubtitleFormatter::format([], $this->style());

        $this->assertStringContainsString('[Events]', $ass);
        $this->assertStringNotContainsString('Dialogue:', $ass);
    }

    public function test_it_writes_a_dialogue_line_per_segment_with_ass_timestamps(): void
    {
        $ass = AssSubtitleFormatter::format([
            ['start' => 0.0, 'end' => 2.4, 'text' => 'Hello world'],
        ], $this->style());

        $this->assertStringContainsString('Dialogue: 0,0:00:00.00,0:00:02.40,Default,Hello world', $ass);
    }

    public function test_it_escapes_newlines_as_ass_hard_line_breaks(): void
    {
        $ass = AssSubtitleFormatter::format([
            ['start' => 0.0, 'end' => 1.0, 'text' => "Line one\nLine two"],
        ], $this->style());

        $this->assertStringContainsString('Line one\\NLine two', $ass);
    }

    public function test_it_rolls_over_minutes_and_hours_correctly(): void
    {
        $ass = AssSubtitleFormatter::format([
            ['start' => 3661.5, 'end' => 3662.0, 'text' => 'One hour in'],
        ], $this->style());

        $this->assertStringContainsString('Dialogue: 0,1:01:01.50,1:01:02.00,Default,One hour in', $ass);
    }
}
