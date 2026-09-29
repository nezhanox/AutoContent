<?php

namespace App\Domain\Source\Support;

final class TranscriptWindower
{
    /**
     * @param  list<Utterance>  $utterances
     * @return list<list<Utterance>>
     */
    public function windows(array $utterances, float $windowSeconds, float $overlapSeconds): array
    {
        $utterances = array_values($utterances);
        if ($utterances === []) {
            return [];
        }

        $first = $utterances[0];
        $last = $utterances[count($utterances) - 1];

        if ($last->end - $first->start <= $windowSeconds) {
            return [$utterances];
        }
        if ($windowSeconds <= $overlapSeconds) {
            throw new \InvalidArgumentException('Window size must be greater than the overlap.');
        }

        $windows = [];
        $windowStart = $first->start;
        while ($windowStart <= $last->start) {
            $windowEnd = $windowStart + $windowSeconds;
            $chunk = array_values(array_filter(
                $utterances,
                fn (Utterance $u) => $u->start >= $windowStart && $u->start < $windowEnd,
            ));
            if ($chunk !== []) {
                $windows[] = $chunk;
                if ($chunk[count($chunk) - 1]->id === $last->id) {
                    break;
                }
            }
            $windowStart += $windowSeconds - $overlapSeconds;
        }

        return $windows;
    }
}
