<?php

namespace Tests\Feature\Domain\Source;

use App\Domain\Source\Support\ClipConstraints;
use App\Domain\Source\Support\ClipValidator;
use App\Domain\Source\Support\Utterance;
use App\Models\Enums\SourceChannelMode;
use Tests\TestCase;

class SourceServiceProviderTest extends TestCase
{
    private function paddedStart(): float
    {
        $utterances = [
            new Utterance(1, 0.0, 10.0, 'a'),
            new Utterance(2, 20.0, 50.0, 'b'),
            new Utterance(3, 50.0, 80.0, 'c'),
            new Utterance(4, 90.0, 100.0, 'd'),
        ];
        $constraints = new ClipConstraints(SourceChannelMode::Highlights, 60, 15, 3, 6);
        $raw = ['start_id' => 2, 'end_id' => 3, 'title' => 'T', 'hook' => 'H', 'score' => 8, 'reason' => 'R'];

        return app(ClipValidator::class)->validate([$raw], $utterances, $constraints)[0]->start;
    }

    public function test_it_builds_the_clip_validator_with_the_configured_padding(): void
    {
        config(['clips.padding_seconds' => 0.5]);
        $this->assertEqualsWithDelta(19.5, $this->paddedStart(), 0.001);

        config(['clips.padding_seconds' => 0.0]);
        $this->assertEqualsWithDelta(20.0, $this->paddedStart(), 0.001);
    }
}
