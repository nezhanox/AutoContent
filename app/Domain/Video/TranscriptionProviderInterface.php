<?php

namespace App\Domain\Video;

interface TranscriptionProviderInterface
{
    public function transcribe(string $audioPath, ?string $language = null): TranscriptionResult;
}
