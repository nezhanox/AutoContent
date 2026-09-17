<?php

namespace App\Domain\Video\Support;

final class AssSubtitleFormatter
{
    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $segments
     * @param  array<string, mixed>  $style
     */
    public static function format(array $segments, array $style): string
    {
        $alignment = match ($style['position'] ?? 'bottom') {
            'top' => 8,
            'middle' => 5,
            default => 2,
        };

        $header = "[Script Info]\nScriptType: v4.00+\nPlayResX: {$style['width']}\nPlayResY: {$style['height']}\n\n"
            ."[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, OutlineColour, Alignment, MarginL, MarginR, MarginV\n"
            ."Style: Default,{$style['font']},{$style['font_size']},{$style['primary_colour']},{$style['outline_colour']},"
            ."{$alignment},{$style['margin_h']},{$style['margin_h']},{$style['margin_v']}\n\n"
            ."[Events]\nFormat: Layer, Start, End, Style, Text\n";

        $lines = array_map(
            static fn (array $segment): string => sprintf(
                'Dialogue: 0,%s,%s,Default,%s',
                self::timestamp($segment['start']),
                self::timestamp($segment['end']),
                str_replace(["\r\n", "\n"], '\\N', $segment['text']),
            ),
            $segments,
        );

        return $header.implode("\n", $lines)."\n";
    }

    private static function timestamp(float $seconds): string
    {
        $whole = (int) floor($seconds);

        return sprintf(
            '%d:%02d:%02d.%02d',
            intdiv($whole, 3600),
            intdiv($whole % 3600, 60),
            $whole % 60,
            (int) round(($seconds - $whole) * 100),
        );
    }
}
