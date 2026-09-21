<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\AudioProbeInterface;

final class FakeAudioProbe implements AudioProbeInterface
{
    private float $fixedDuration = 10.0;

    public function respondWith(float $duration): static
    {
        $this->fixedDuration = $duration;

        return $this;
    }

    public function duration(string $audioContent): float
    {
        return $this->fixedDuration;
    }
}
