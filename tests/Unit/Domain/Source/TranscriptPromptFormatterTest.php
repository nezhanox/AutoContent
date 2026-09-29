<?php

namespace Tests\Unit\Domain\Source;

use App\Domain\Source\Support\TranscriptPromptFormatter;
use App\Domain\Source\Support\Utterance;
use PHPUnit\Framework\TestCase;

final class TranscriptPromptFormatterTest extends TestCase
{
    public function test_it_formats_single_utterance(): void
    {
        $formatter = new TranscriptPromptFormatter;
        $utterance = new Utterance(id: 12, start: 221.4, end: 227.1, text: 'So the real problem.');

        $result = $formatter->format([$utterance]);

        $this->assertSame('[12] 03:41–03:47 (6s) So the real problem.', $result);
    }

    public function test_it_formats_multiple_utterances_separated_by_newlines(): void
    {
        $formatter = new TranscriptPromptFormatter;
        $utterances = [
            new Utterance(id: 1, start: 0.0, end: 5.0, text: 'First utterance.'),
            new Utterance(id: 2, start: 5.0, end: 10.0, text: 'Second utterance.'),
        ];

        $result = $formatter->format($utterances);

        $this->assertSame(
            "[1] 00:00–00:05 (5s) First utterance.\n[2] 00:05–00:10 (5s) Second utterance.",
            $result
        );
    }

    public function test_it_formats_time_correctly_for_hours_without_truncation(): void
    {
        $formatter = new TranscriptPromptFormatter;
        $utterance = new Utterance(id: 1, start: 4500.0, end: 4510.0, text: 'Test');

        $result = $formatter->format([$utterance]);

        $this->assertStringContainsString('75:00–75:10', $result);
    }
}
