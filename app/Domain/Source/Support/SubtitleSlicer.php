<?php

namespace App\Domain\Source\Support;

final class SubtitleSlicer
{
    public function __construct(private readonly int $maxWords = 6) {}

    /**
     * @param  array<int,array{start:float,end:float,text:string}>  $segments
     * @return array<int,array{start:float,end:float,text:string}>
     */
    public function slice(array $segments, float $clipStart, float $clipEnd): array
    {
        $out = [];
        foreach ($segments as $s) {
            if ($s['end'] <= $clipStart || $s['start'] >= $clipEnd) {
                continue;
            }
            $start = max((float) $s['start'], $clipStart);
            $end = min((float) $s['end'], $clipEnd);
            foreach ($this->chunk((string) $s['text'], $start, $end) as $c) {
                $out[] = [
                    'start' => round($c['start'] - $clipStart, 3),
                    'end' => round($c['end'] - $clipStart, 3),
                    'text' => $c['text'],
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<int,array{start:float,end:float,text:string}>
     */
    private function chunk(string $text, float $start, float $end): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return [];
        }
        $total = count($words);
        $span = $end - $start;
        $cursor = $start;
        $done = 0;
        $chunks = [];
        foreach (array_chunk($words, $this->maxWords) as $group) {
            $done += count($group);
            $chunkEnd = $start + $span * $done / $total;
            $chunks[] = ['start' => $cursor, 'end' => $chunkEnd, 'text' => implode(' ', $group)];
            $cursor = $chunkEnd;
        }

        return $chunks;
    }
}
