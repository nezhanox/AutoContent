<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\TranscriptionProviderInterface;
use App\Domain\Video\TranscriptionResult;

final class FakeTranscriptionProvider implements TranscriptionProviderInterface
{
    /** @var array<int, array{start: float, end: float, text: string}> */
    private array $segments = [];

    private string $language = 'en';

    public ?string $lastAudioPath = null;

    public ?string $lastLanguageArgument = null;

    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $segments
     */
    public function respondWith(array $segments, string $language = 'en'): static
    {
        $this->segments = $segments;
        $this->language = $language;

        return $this;
    }

    public function transcribe(string $audioPath, ?string $language = null): TranscriptionResult
    {
        $this->lastAudioPath = $audioPath;
        $this->lastLanguageArgument = $language;

        return new TranscriptionResult(segments: $this->segments, language: $this->language);
    }
}
