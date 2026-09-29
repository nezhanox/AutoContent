<?php

namespace Tests\Unit\Domain\Source;

use App\Domain\Source\Exceptions\InvalidClipSelectionException;
use App\Domain\Source\Support\ClipConstraints;
use App\Domain\Source\Support\ClipValidator;
use App\Domain\Source\Support\Utterance;
use App\Models\Enums\SourceChannelMode;
use PHPUnit\Framework\TestCase;

class ClipValidatorTest extends TestCase
{
    /**
     * @param  list<int|float>  $lengths
     * @return list<Utterance>
     */
    private function utterances(array $lengths, float $gap = 0.5): array
    {
        $result = [];
        $cursor = 0.0;
        foreach ($lengths as $i => $len) {
            $result[] = new Utterance($i + 1, $cursor, $cursor + $len, 'Utterance '.($i + 1));
            $cursor += $len + $gap;
        }

        return $result;
    }

    private function highlights(int $maxClips = 3, int $minScore = 6): ClipConstraints
    {
        return new ClipConstraints(SourceChannelMode::Highlights, 60, 15, $maxClips, $minScore);
    }

    private function fixed(): ClipConstraints
    {
        return new ClipConstraints(SourceChannelMode::Fixed, 60, 15, 10, 0);
    }

    /**
     * @return array<string,mixed>
     */
    private function raw(int $start, int $end, ?int $score = 8, string $title = 'Title'): array
    {
        $raw = ['start_id' => $start, 'end_id' => $end, 'title' => $title, 'hook' => 'Hook', 'reason' => 'Because'];
        if ($score !== null) {
            $raw['score'] = $score;
        }

        return $raw;
    }

    public function test_it_pads_and_returns_valid_clip(): void
    {
        $utterances = $this->utterances(array_fill(0, 12, 10));

        $clips = (new ClipValidator)->validate([$this->raw(2, 7)], $utterances, $this->highlights());

        $this->assertCount(1, $clips);
        $this->assertEqualsWithDelta(10.35, $clips[0]->start, 0.001);
        $this->assertEqualsWithDelta(73.15, $clips[0]->end, 0.001);
        $this->assertSame(8, $clips[0]->score);
        $this->assertSame('Title', $clips[0]->title);
    }

    public function test_it_rejects_unknown_utterance_id(): void
    {
        $this->expectException(InvalidClipSelectionException::class);
        $this->expectExceptionMessage('unknown utterance id 99');

        (new ClipValidator)->validate([$this->raw(1, 99)], $this->utterances([50, 50]), $this->highlights());
    }

    public function test_it_rejects_missing_start_id(): void
    {
        $this->expectException(InvalidClipSelectionException::class);
        $this->expectExceptionMessage('missing integer [start_id]');

        (new ClipValidator)->validate([['end_id' => 1, 'score' => 8]], $this->utterances([50]), $this->highlights());
    }

    public function test_it_rejects_start_after_end(): void
    {
        $this->expectException(InvalidClipSelectionException::class);
        $this->expectExceptionMessage('start_id after end_id');

        (new ClipValidator)->validate([$this->raw(2, 1)], $this->utterances([50, 50]), $this->highlights());
    }

    public function test_it_rejects_overlapping_clips(): void
    {
        $this->expectException(InvalidClipSelectionException::class);
        $this->expectExceptionMessage('overlap');

        (new ClipValidator)->validate(
            [$this->raw(1, 6), $this->raw(5, 10)],
            $this->utterances(array_fill(0, 10, 10)),
            $this->highlights(),
        );
    }

    public function test_it_rejects_too_long_clip(): void
    {
        $this->expectException(InvalidClipSelectionException::class);
        $this->expectExceptionMessage('must be between');

        (new ClipValidator)->validate([$this->raw(1, 1)], $this->utterances([80, 80]), $this->highlights());
    }

    public function test_it_rejects_too_short_non_tail_clip_in_highlights(): void
    {
        $this->expectException(InvalidClipSelectionException::class);
        $this->expectExceptionMessage('must be between');

        (new ClipValidator)->validate([$this->raw(1, 1)], $this->utterances([20, 20]), $this->highlights());
    }

    public function test_it_rejects_missing_score_in_highlights(): void
    {
        $this->expectException(InvalidClipSelectionException::class);

        (new ClipValidator)->validate([$this->raw(1, 1, null)], $this->utterances([50, 50]), $this->highlights());
    }

    public function test_it_keeps_top_scored_clips_in_chronological_order(): void
    {
        $raws = [$this->raw(1, 1, 9), $this->raw(2, 2, 5), $this->raw(3, 3, 8), $this->raw(4, 4, 7)];

        $clips = (new ClipValidator)->validate($raws, $this->utterances([50, 50, 50, 50]), $this->highlights(2, 6));

        $this->assertCount(2, $clips);
        $this->assertSame(9, $clips[0]->score);
        $this->assertSame(8, $clips[1]->score);
        $this->assertLessThan($clips[1]->start, $clips[0]->start);
    }

    public function test_it_keeps_fixed_tail_when_long_enough(): void
    {
        $raws = [$this->raw(1, 1, null), $this->raw(2, 2, null), $this->raw(3, 3, null)];

        $clips = (new ClipValidator)->validate($raws, $this->utterances([50, 50, 12]), $this->fixed());

        $this->assertCount(3, $clips);
    }

    public function test_it_drops_fixed_tail_when_too_short(): void
    {
        $raws = [$this->raw(1, 1, null), $this->raw(2, 2, null), $this->raw(3, 3, null)];

        $clips = (new ClipValidator)->validate($raws, $this->utterances([50, 50, 8]), $this->fixed());

        $this->assertCount(2, $clips);
    }

    public function test_it_rejects_short_fixed_tail_when_not_final_window(): void
    {
        $this->expectException(InvalidClipSelectionException::class);

        $raws = [$this->raw(1, 1, null), $this->raw(2, 2, null), $this->raw(3, 3, null)];

        (new ClipValidator)->validate($raws, $this->utterances([50, 50, 12]), $this->fixed(), false);
    }

    public function test_it_falls_back_to_utterance_text_for_empty_title(): void
    {
        $clips = (new ClipValidator)->validate([$this->raw(1, 1, 8, '  ')], $this->utterances([50, 50]), $this->highlights());

        $this->assertSame('Utterance 1', $clips[0]->title);
    }
}
