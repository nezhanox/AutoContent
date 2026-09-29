<?php

namespace App\Domain\Source\Support;

final class TranscriptPromptFormatter
{
    /**
     * @param  list<Utterance>  $utterances
     */
    public function format(array $utterances): string
    {
        return implode("\n", array_map(fn (Utterance $u): string => sprintf(
            '[%d] %s–%s (%ds) %s',
            $u->id,
            $this->clock($u->start),
            $this->clock($u->end),
            (int) round($u->duration()),
            $u->text,
        ), $utterances));
    }

    private function clock(float $seconds): string
    {
        $t = (int) floor($seconds);

        return sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
    }
}
