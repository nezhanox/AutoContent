<?php

namespace App\Domain\Video;

use App\Models\Video;

interface VideoQualityCheckerInterface
{
    public function check(Video $video): QualityCheckResult;
}
