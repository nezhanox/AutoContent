<?php

namespace App\Domain\Video\Support;

final class SrtFormatter
{
    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $segments
     */
    public static function format(array $segments): string
    {
        if ($segments === []) {
            return '';
        }

        $blocks = [];

        foreach ($segments as $index => $segment) {
            $blocks[] = ($index + 1)."\n"
                .self::timestamp($segment['start']).' --> '.self::timestamp($segment['end'])."\n"
                .$segment['text'];
        }

        return implode("\n\n", $blocks)."\n";
    }

    private static function timestamp(float $seconds): string
    {
        $total = (int) round($seconds * 1000);
        $hours = intdiv($total, 3_600_000);
        $minutes = intdiv($total % 3_600_000, 60_000);
        $secs = intdiv($total % 60_000, 1000);
        $millis = $total % 1000;

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $millis);
    }
}
