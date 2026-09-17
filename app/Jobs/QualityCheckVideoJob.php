<?php

namespace App\Jobs;

use App\Domain\Video\VideoQualityCheckerInterface;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class QualityCheckVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 420;

    public int $tries = 3;

    public int $uniqueFor = 450;

    public function __construct(public readonly int $videoId)
    {
        $this->onQueue('render');
    }

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(VideoQualityCheckerInterface $checker): void
    {
        $video = Video::with('scenes')->findOrFail($this->videoId);

        if ($video->status !== VideoStatus::Rendered) {
            return;
        }

        $result = $checker->check($video);

        $video->update([
            'quality_passed' => $result->passed,
            'quality_report' => [
                'checks' => $result->checks,
                'notes' => $result->notes,
                'metadata' => $result->metadata,
            ],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('video')->error('Quality check failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
