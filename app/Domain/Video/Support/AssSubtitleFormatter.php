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

        $outlineWidth = $style['outline_width'] ?? 2;
        $shadowWidth = $style['shadow_width'] ?? 0;
        $bold = ($style['bold'] ?? true) ? -1 : 0;

        $header = "[Script Info]\nScriptType: v4.00+\nPlayResX: {$style['width']}\nPlayResY: {$style['height']}\n\n"
            ."[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, OutlineColour, Bold, BorderStyle, Outline, "
            ."Shadow, Alignment, MarginL, MarginR, MarginV\n"
            ."Style: Default,{$style['font']},{$style['font_size']},{$style['primary_colour']},{$style['outline_colour']},"
            // Bold=-1 (ASS boolean true, `bold` style key; 0 for families that
            // already ship a heavy static weight) and BorderStyle=1 (outline+drop shadow,
            // not an opaque box) keep every word legible over a busy photo
            // without hiding the photo behind a solid caption background.
            ."{$bold},1,{$outlineWidth},{$shadowWidth},"
            ."{$alignment},{$style['margin_h']},{$style['margin_h']},{$style['margin_v']}\n\n"
            ."[Events]\nFormat: Layer, Start, End, Style, Text\n";

        $positiveColour = $style['accent_colour_positive'] ?? null;
        $negativeColour = $style['accent_colour_negative'] ?? null;

        $lines = array_map(
            static function (array $segment) use ($positiveColour, $negativeColour): string {
                $text = $positiveColour !== null && $negativeColour !== null
                    ? self::highlightWords($segment['text'], $positiveColour, $negativeColour)
                    : $segment['text'];

                return sprintf(
                    'Dialogue: 0,%s,%s,Default,%s',
                    self::timestamp($segment['start']),
                    self::timestamp($segment['end']),
                    str_replace(["\r\n", "\n"], '\\N', $text),
                );
            },
            $segments,
        );

        return $header.implode("\n", $lines)."\n";
    }

    private static function highlightWords(string $text, string $positiveColour, string $negativeColour): string
    {
        $tokens = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        return implode('', array_map(
            static function (string $token) use ($positiveColour, $negativeColour): string {
                if (trim($token) === '') {
                    return $token;
                }

                return match (CaptionHighlighter::classify($token)) {
                    'positive' => "{\\c{$positiveColour}&}{$token}{\\c}",
                    'negative' => "{\\c{$negativeColour}&}{$token}{\\c}",
                    default => $token,
                };
            },
            $tokens,
        ));
    }

    private static function timestamp(float $seconds): string
    {
        $totalCentiseconds = (int) round($seconds * 100);
        $wholeSeconds = intdiv($totalCentiseconds, 100);
        $centiseconds = $totalCentiseconds % 100;

        return sprintf(
            '%d:%02d:%02d.%02d',
            intdiv($wholeSeconds, 3600),
            intdiv($wholeSeconds % 3600, 60),
            $wholeSeconds % 60,
            $centiseconds,
        );
    }
}
