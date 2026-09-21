<?php

namespace App\Domain\Video;

interface AudioProbeInterface
{
    /**
     * Duration of the given audio content, in seconds.
     */
    public function duration(string $audioContent): float;
}
