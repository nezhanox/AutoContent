<?php

namespace Tests\Unit\Domain\Source;

use App\Domain\Source\Support\UtteranceBuilder;
use PHPUnit\Framework\TestCase;

final class UtteranceBuilderTest extends TestCase
{
    public function test_it_merges_consecutive_segments_without_pause(): void
    {
        $builder = new UtteranceBuilder(pauseSeconds: 0.7, maxSeconds: 15.0);
        $segments = [
            ['start' => 0.0, 'end' => 2.0, 'text' => 'So the real'],
            ['start' => 2.0, 'end' => 4.0, 'text' => 'problem is this.'],
        ];

        $utterances = $builder->build($segments);

        $this->assertCount(1, $utterances);
        $this->assertSame(1, $utterances[0]->id);
        $this->assertSame(0.0, $utterances[0]->start);
        $this->assertSame(4.0, $utterances[0]->end);
        $this->assertSame('So the real problem is this.', $utterances[0]->text);
    }

    public function test_it_splits_utterances_on_pause_greater_than_pause_seconds(): void
    {
        $builder = new UtteranceBuilder(pauseSeconds: 0.7, maxSeconds: 15.0);
        $segments = [
            ['start' => 0.0, 'end' => 2.0, 'text' => 'Hello there'],
            ['start' => 3.0, 'end' => 5.0, 'text' => 'again friend'],
        ];

        $utterances = $builder->build($segments);

        $this->assertCount(2, $utterances);
        $this->assertSame(1, $utterances[0]->id);
        $this->assertSame(2, $utterances[1]->id);
        $this->assertSame('Hello there', $utterances[0]->text);
        $this->assertSame('again friend', $utterances[1]->text);
    }

    public function test_it_splits_utterances_on_max_duration(): void
    {
        $builder = new UtteranceBuilder(pauseSeconds: 0.7, maxSeconds: 10.0);
        $segments = [
            ['start' => 0.0, 'end' => 6.0, 'text' => 'segment one'],
            ['start' => 6.0, 'end' => 12.0, 'text' => 'segment two'],
            ['start' => 12.0, 'end' => 18.0, 'text' => 'segment three'],
        ];

        $utterances = $builder->build($segments);

        $this->assertCount(2, $utterances);
        $this->assertSame(1, $utterances[0]->id);
        $this->assertSame(0.0, $utterances[0]->start);
        $this->assertSame(12.0, $utterances[0]->end);
        $this->assertSame(2, $utterances[1]->id);
        $this->assertSame(12.0, $utterances[1]->start);
        $this->assertSame(18.0, $utterances[1]->end);
    }

    public function test_it_ignores_empty_segments(): void
    {
        $builder = new UtteranceBuilder(pauseSeconds: 0.7, maxSeconds: 15.0);
        $segments = [
            ['start' => 0.0, 'end' => 1.0, 'text' => 'hello'],
            ['start' => 1.0, 'end' => 1.5, 'text' => '   '],
            ['start' => 1.5, 'end' => 2.5, 'text' => 'world'],
        ];

        $utterances = $builder->build($segments);

        $this->assertCount(1, $utterances);
        $this->assertSame('hello world', $utterances[0]->text);
    }

    public function test_it_closes_utterance_on_sentence_ending_punctuation(): void
    {
        $builder = new UtteranceBuilder(pauseSeconds: 0.7, maxSeconds: 15.0);
        $segments = [
            ['start' => 0.0, 'end' => 1.0, 'text' => 'He said "stop."'],
            ['start' => 1.0, 'end' => 2.0, 'text' => 'Then he left.'],
        ];

        $utterances = $builder->build($segments);

        $this->assertCount(2, $utterances);
        $this->assertSame('He said "stop."', $utterances[0]->text);
        $this->assertSame('Then he left.', $utterances[1]->text);
    }

    public function test_it_closes_utterance_on_curly_quotes_around_sentence_ending(): void
    {
        $builder = new UtteranceBuilder(pauseSeconds: 0.7, maxSeconds: 15.0);
        $segments = [
            ['start' => 0.0, 'end' => 1.0, 'text' => "He said \u{201C}stop.\u{201D}"],
            ['start' => 1.0, 'end' => 2.0, 'text' => 'Then he left.'],
        ];

        $utterances = $builder->build($segments);

        $this->assertCount(2, $utterances);
        $this->assertSame("He said \u{201C}stop.\u{201D}", $utterances[0]->text);
        $this->assertSame('Then he left.', $utterances[1]->text);
    }
}
