<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\QualityCheckResult;
use App\Domain\Video\VideoQualityCheckerInterface;
use App\Models\Video;

final class FakeVideoQualityChecker implements VideoQualityCheckerInterface
{
    private ?QualityCheckResult $result = null;

    public function respondWith(QualityCheckResult $result): static
    {
        $this->result = $result;

        return $this;
    }

    public function check(Video $video): QualityCheckResult
    {
        return $this->result ?? new QualityCheckResult(passed: true, checks: ['has_video_stream' => true]);
    }
}
