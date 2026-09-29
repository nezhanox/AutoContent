<?php

namespace App\Domain\Source\Support;

use App\Domain\Source\Exceptions\InvalidClipSelectionException;
use App\Models\Enums\SourceChannelMode;

final class ClipValidator
{
    public function __construct(private readonly float $paddingSeconds = 0.15) {}

    /**
     * @param  array<int,array<string,mixed>>  $rawClips  items {start_id,end_id,title,hook,score,reason}
     * @param  list<Utterance>  $utterances
     * @return list<Clip> ordered by start
     *
     * Ids are resolved and overlaps are checked strictly (InvalidClipSelectionException).
     * Durations are never rejected: the LLM picks the moment, and each clip's boundaries are
     * fitted deterministically by whole utterances into [target - tolerance, target + tolerance]
     * (extend forward then backward when too short, trim from the end then the start when too
     * long, without crossing neighbouring clips). Clips that cannot be fitted are dropped
     * silently (except the Fixed-mode final tail, which may stay short if >= minTailSeconds),
     * so an empty list is a valid result. Score filtering and the maxClips cap run after fitting.
     *
     * @throws InvalidClipSelectionException
     */
    public function validate(array $rawClips, array $utterances, ClipConstraints $constraints, bool $finalWindow = true): array
    {
        $utterances = array_values($utterances);
        $positions = [];
        foreach ($utterances as $pos => $utterance) {
            $positions[$utterance->id] = $pos;
        }

        // 1. Resolve ids to positions.
        $items = [];
        $n = 0;
        foreach ($rawClips as $raw) {
            $n++;
            $startPos = $this->resolvePosition($raw, 'start_id', $positions, $n);
            $endPos = $this->resolvePosition($raw, 'end_id', $positions, $n);
            if ($startPos > $endPos) {
                throw new InvalidClipSelectionException("clip #{$n} has start_id after end_id.");
            }
            $items[] = ['n' => $n, 'raw' => $raw, 'startPos' => $startPos, 'endPos' => $endPos];
        }

        // 2. Sort and check overlaps.
        usort($items, fn (array $a, array $b) => $a['startPos'] <=> $b['startPos']);
        for ($i = 1; $i < count($items); $i++) {
            if ($items[$i]['startPos'] <= $items[$i - 1]['endPos']) {
                throw new InvalidClipSelectionException("clips #{$items[$i - 1]['n']} and #{$items[$i]['n']} overlap.");
            }
        }

        // 3. Fit each clip into the duration range by whole utterances.
        $isFixed = $constraints->mode === SourceChannelMode::Fixed;
        $min = $constraints->minSeconds() - 0.5;
        $max = $constraints->maxSeconds() + 0.5;
        $kept = [];
        $last = count($items) - 1;
        $prevEnd = -1;
        foreach ($items as $i => $item) {
            $lowerBound = $prevEnd + 1;
            $upperBound = isset($items[$i + 1]) ? $items[$i + 1]['startPos'] - 1 : count($utterances) - 1;
            $prevEnd = $item['endPos'];

            $fit = $this->fit($utterances, $item['startPos'], $item['endPos'], $lowerBound, $upperBound, $min, $max);
            $duration = $utterances[$fit[1]]->end - $utterances[$fit[0]]->start;

            if ($duration < $min && $isFixed && $finalWindow && $i === $last && $duration >= $constraints->minTailSeconds) {
                // Fixed-mode tail: allowed to stay shorter than the minimum.
            } elseif ($duration < $min || $duration > $max) {
                continue;
            }

            $item['startPos'] = $fit[0];
            $item['endPos'] = $fit[1];
            $prevEnd = $fit[1];
            $kept[] = $item;
        }

        // 4. Scores.
        if ($constraints->mode === SourceChannelMode::Highlights) {
            foreach ($kept as $item) {
                $score = $item['raw']['score'] ?? null;
                if (! is_int($score)) {
                    throw new InvalidClipSelectionException("clip #{$item['n']} is missing integer [score].");
                }
            }
            $kept = array_values(array_filter($kept, fn (array $item) => $item['raw']['score'] >= $constraints->minScore));
            usort($kept, fn (array $a, array $b) => $b['raw']['score'] <=> $a['raw']['score']);
            $kept = array_slice($kept, 0, $constraints->maxClips);
            usort($kept, fn (array $a, array $b) => $a['startPos'] <=> $b['startPos']);
        }

        // 5. Padding and construction.
        $clips = [];
        foreach ($kept as $item) {
            $first = $utterances[$item['startPos']];
            $lastU = $utterances[$item['endPos']];
            $prev = $utterances[$item['startPos'] - 1] ?? null;
            $next = $utterances[$item['endPos'] + 1] ?? null;

            $start = min($first->start, max($first->start - $this->paddingSeconds, $prev ? $prev->end : 0.0));
            $end = max($lastU->end, min($lastU->end + $this->paddingSeconds, $next ? $next->start : $lastU->end + $this->paddingSeconds));

            $title = trim((string) ($item['raw']['title'] ?? ''));
            if ($title === '') {
                $title = mb_substr($first->text, 0, 60);
            }
            $title = mb_substr($title, 0, 255);

            $score = $item['raw']['score'] ?? null;
            $clips[] = new Clip(
                start: $start,
                end: $end,
                title: $title,
                hook: isset($item['raw']['hook']) ? (string) $item['raw']['hook'] : null,
                score: is_int($score) ? $score : null,
                reason: isset($item['raw']['reason']) ? (string) $item['raw']['reason'] : null,
            );
        }

        return $clips;
    }

    /**
     * @param  list<Utterance>  $u
     * @return array{0:int,1:int} fitted [startPos, endPos]; may still be out of range if unfittable
     */
    private function fit(array $u, int $start, int $end, int $lower, int $upper, float $min, float $max): array
    {
        $dur = fn (int $s, int $e): float => $u[$e]->end - $u[$s]->start;

        if ($dur($start, $end) < $min) {
            while ($dur($start, $end) < $min && $end + 1 <= $upper && $dur($start, $end + 1) <= $max) {
                $end++;
            }
            while ($dur($start, $end) < $min && $start - 1 >= $lower && $dur($start - 1, $end) <= $max) {
                $start--;
            }

            return [$start, $end];
        }

        if ($dur($start, $end) > $max) {
            $e = $end;
            while ($e > $start && $dur($start, $e) > $max) {
                $e--;
            }
            if ($dur($start, $e) >= $min && $dur($start, $e) <= $max) {
                return [$start, $e];
            }
            $s = $start;
            while ($s < $end && $dur($s, $end) > $max) {
                $s++;
            }
            if ($dur($s, $end) >= $min && $dur($s, $end) <= $max) {
                return [$s, $end];
            }
        }

        return [$start, $end];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @param  array<int,int>  $positions
     */
    private function resolvePosition(array $raw, string $key, array $positions, int $n): int
    {
        $id = $raw[$key] ?? null;
        if (! is_int($id)) {
            throw new InvalidClipSelectionException("clip #{$n} is missing integer [{$key}].");
        }
        if (! isset($positions[$id])) {
            throw new InvalidClipSelectionException("clip #{$n} references unknown utterance id {$id}.");
        }

        return $positions[$id];
    }
}
