<?php

namespace App\Domain\Source\Support;

final class UtteranceBuilder
{
    public function __construct(
        private readonly float $pauseSeconds = 0.7,
        private readonly float $maxSeconds = 15.0,
    ) {}

    /**
     * @param  array<int,array{start:float,end:float,text:string}>  $segments
     * @return list<Utterance>
     */
    public function build(array $segments): array
    {
        $utterances = [];
        $id = 1;
        $start = null;
        $end = 0.0;
        $parts = [];

        $flush = function () use (&$utterances, &$id, &$start, &$end, &$parts): void {
            if ($parts === []) {
                return;
            }
            $utterances[] = new Utterance($id++, (float) $start, (float) $end, implode(' ', $parts));
            $start = null;
            $parts = [];
        };

        foreach ($segments as $segment) {
            $text = trim((string) $segment['text']);
            if ($text === '') {
                continue;
            }
            if ($parts !== [] && ((float) $segment['start'] - $end) > $this->pauseSeconds) {
                $flush();
            }
            $start ??= (float) $segment['start'];
            $end = (float) $segment['end'];
            $parts[] = $text;
            if (preg_match('/[.!?…]["\'"»)\]]*$/u', $text) === 1 || ($end - $start) >= $this->maxSeconds) {
                $flush();
            }
        }
        $flush();

        return $utterances;
    }
}
