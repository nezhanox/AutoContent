<?php

namespace App\Domain\Video\Support;

final class CaptionHighlighter
{
    // Word stems (not exact forms), covering both Russian and Ukrainian
    // inflections, e.g. "слаб" catches "слабость"/"слабкий"/"слабак".
    private const POSITIVE_STEMS = [
        'сил', 'мудр', 'свобод', 'спокой', 'спокі', 'стойк', 'стійк', 'дисциплин',
        'вол', 'контрол', 'побед', 'перемог', 'характер', 'разум', 'достоин',
        'гідніст', 'непоколеб', 'опыт', 'досвід', 'рост',
    ];

    private const NEGATIVE_STEMS = [
        'слаб', 'страх', 'завис', 'разруша', 'руйну', 'уничтож', 'знищ', 'ненавист',
        'боль', 'біль', 'потер', 'предательств', 'зрад', 'обман', 'рабств', 'бесит',
        'опасн', 'небезпе', 'разочаров', 'розчаров', 'порожн', 'борг',
    ];

    /**
     * @return 'positive'|'negative'|null
     */
    public static function classify(string $word): ?string
    {
        $normalized = self::normalize($word);

        if ($normalized === '') {
            return null;
        }

        foreach (self::POSITIVE_STEMS as $stem) {
            if (str_starts_with($normalized, $stem)) {
                return 'positive';
            }
        }

        foreach (self::NEGATIVE_STEMS as $stem) {
            if (str_starts_with($normalized, $stem)) {
                return 'negative';
            }
        }

        return null;
    }

    private static function normalize(string $word): string
    {
        $stripped = preg_replace('/[^\p{L}\p{N}]+/u', '', $word) ?? '';

        return mb_strtolower($stripped);
    }
}
