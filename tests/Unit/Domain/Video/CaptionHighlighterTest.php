<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Support\CaptionHighlighter;
use Tests\TestCase;

class CaptionHighlighterTest extends TestCase
{
    public function test_it_classifies_a_positive_stem_word(): void
    {
        $this->assertSame('positive', CaptionHighlighter::classify('силу'));
        $this->assertSame('positive', CaptionHighlighter::classify('Мудрость'));
    }

    public function test_it_classifies_a_negative_stem_word(): void
    {
        $this->assertSame('negative', CaptionHighlighter::classify('слабость'));
        $this->assertSame('negative', CaptionHighlighter::classify('Страха'));
    }

    public function test_it_returns_null_for_an_unmatched_word(): void
    {
        $this->assertNull(CaptionHighlighter::classify('привет'));
    }

    public function test_it_strips_surrounding_punctuation_before_matching(): void
    {
        $this->assertSame('negative', CaptionHighlighter::classify('«слабость,'));
    }

    public function test_it_returns_null_for_blank_input(): void
    {
        $this->assertNull(CaptionHighlighter::classify('   '));
    }
}
