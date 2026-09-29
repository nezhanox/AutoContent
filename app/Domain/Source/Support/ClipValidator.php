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

        // 3. Duration checks.
        $isFixed = $constraints->mode === SourceChannelMode::Fixed;
        $kept = [];
        $last = count($items) - 1;
        foreach ($items as $i => $item) {
            $duration = $utterances[$item['endPos']]->end - $utterances[$item['startPos']]->start;
            $min = $constraints->minSeconds();
            $max = $constraints->maxSeconds();
            $message = sprintf('clip #%d lasts %.1fs but must be between %d and %d seconds.', $item['n'], $duration, $min, $max);

            if ($duration > $max + 0.5) {
                throw new InvalidClipSelectionException($message);
            }
            if ($duration < $min - 0.5) {
                if ($isFixed && $finalWindow && $i === $last) {
                    if ($duration < $constraints->minTailSeconds) {
                        continue;
                    }
                } else {
                    throw new InvalidClipSelectionException($message);
                }
            }
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
