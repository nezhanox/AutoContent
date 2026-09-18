<?php

namespace App\Jobs;

use App\Domain\Publishing\SocialPublisherInterface;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\VideoMetric;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class CollectVideoMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public function __construct(public readonly int $publicationId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60, 120];
    }

    public function handle(SocialPublisherInterface $publisher): void
    {
        $publication = Publication::findOrFail($this->publicationId);

        if ($publication->status !== PublicationStatus::Published) {
            return;
        }

        $result = $publisher->fetchMetrics($publication);

        VideoMetric::create([
            'publication_id' => $publication->id,
            'views' => $result->views,
            'likes' => $result->likes,
            'comments' => $result->comments,
            'shares' => $result->shares,
            'saves' => $result->saves,
            'watch_time' => $result->watchTime,
            'completion_rate' => $result->completionRate,
            'followers_gained' => $result->followersGained,
            'metadata' => $result->metadata,
            'measured_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->notifyPermanentFailure('analytics', 'Metrics collection failed permanently.', [
            'publication_id' => $this->publicationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
