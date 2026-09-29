<?php

namespace App\Domain\Source\Support;

use App\Models\Enums\SourceChannelMode;

final class ClipMerger
{
    /**
     * @param  list<Clip>  $clips
     * @return list<Clip>
     */
    public function merge(array $clips, ClipConstraints $constraints): array
    {
        $highlights = $constraints->mode === SourceChannelMode::Highlights;

        $sorted = array_values($clips);
        usort($sorted, $highlights
            ? fn (Clip $a, Clip $b) => ($b->score ?? 0) <=> ($a->score ?? 0)
            : fn (Clip $a, Clip $b) => $a->start <=> $b->start);

        $accepted = [];
        foreach ($sorted as $clip) {
            foreach ($accepted as $existing) {
                if ($clip->start < $existing->end && $clip->end > $existing->start) {
                    continue 2;
                }
            }
            $accepted[] = $clip;
        }

        if ($highlights) {
            $accepted = array_slice($accepted, 0, $constraints->maxClips);
        }

        usort($accepted, fn (Clip $a, Clip $b) => $a->start <=> $b->start);

        return $accepted;
    }
}
