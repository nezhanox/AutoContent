<?php

namespace App\Domain\Publishing\Providers;

use App\Domain\Publishing\PublishResult;
use App\Domain\Publishing\SocialPublisherInterface;
use App\Domain\Publishing\VideoMetricsResult;
use App\Models\Publication;
use Illuminate\Support\Str;

final class FakeSocialPublisher implements SocialPublisherInterface
{
    private ?PublishResult $result = null;

    private ?VideoMetricsResult $metricsResult = null;

    public function respondWith(PublishResult $result): static
    {
        $this->result = $result;

        return $this;
    }

    public function respondWithMetrics(VideoMetricsResult $result): static
    {
        $this->metricsResult = $result;

        return $this;
    }

    public function publish(Publication $publication): PublishResult
    {
        return $this->result ?? new PublishResult(
            externalPostId: 'fake-'.Str::uuid(),
            metadata: ['platform' => $publication->socialAccount->platform->value],
        );
    }

    public function fetchMetrics(Publication $publication): VideoMetricsResult
    {
        if ($this->metricsResult !== null) {
            return $this->metricsResult;
        }

        $previous = $publication->videoMetrics()->latest('measured_at')->first();

        return new VideoMetricsResult(
            views: ($previous->views ?? 0) + random_int(50, 5000),
            likes: ($previous->likes ?? 0) + random_int(5, 500),
            comments: ($previous->comments ?? 0) + random_int(0, 50),
            shares: ($previous->shares ?? 0) + random_int(0, 20),
        );
    }
}
