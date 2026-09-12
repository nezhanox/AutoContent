<?php

namespace App\Domain\Video;

interface TtsProviderInterface
{
    public function generate(string $text, VoiceSettings $settings): VoiceResult;
}
