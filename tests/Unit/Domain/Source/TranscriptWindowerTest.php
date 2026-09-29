<?php

namespace Tests\Unit\Domain\Source;

use App\Domain\Source\Support\TranscriptWindower;
use App\Domain\Source\Support\Utterance;
use PHPUnit\Framework\TestCase;

class TranscriptWindowerTest extends TestCase
{
    /**
     * @return list<Utterance>
     */
    private function utterances(int $count): array
    {
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = new Utterance($i + 1, $i * 10.0, $i * 10.0 + 10.0, 'u'.($i + 1));
        }

        return $result;
    }

    public function test_it_splits_into_overlapping_windows(): void
    {
        $windows = (new TranscriptWindower)->windows($this->utterances(10), 40, 10);

        $ids = array_map(fn (array $w) => array_map(fn (Utterance $u) => $u->id, $w), $windows);

        $this->assertSame([[1, 2, 3, 4], [4, 5, 6, 7], [7, 8, 9, 10]], $ids);
    }

    public function test_it_returns_single_window_for_short_video(): void
    {
        $utterances = $this->utterances(3);

        $windows = (new TranscriptWindower)->windows($utterances, 100, 10);

        $this->assertCount(1, $windows);
        $this->assertCount(3, $windows[0]);
    }

    public function test_it_rejects_window_not_larger_than_overlap(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TranscriptWindower)->windows($this->utterances(10), 10, 10);
    }
}
