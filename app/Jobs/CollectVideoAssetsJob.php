<?php

namespace App\Jobs;

use App\Domain\Video\Services\CollectVideoAssetsService;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectVideoAssetsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public int $uniqueFor = 200;

    public function __construct(public readonly int $videoId) {}

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

    public function handle(CollectVideoAssetsService $service): void
    {
        $video = Video::with('scenes')->findOrFail($this->videoId);

        if ($video->status !== VideoStatus::VoiceGenerated) {
            return;
        }

        $assignments = $service->collect($video);

        DB::transaction(function () use ($video, $assignments) {
            foreach ($assignments as $sceneId => $assetId) {
                VideoScene::whereKey($sceneId)->update(['asset_id' => $assetId]);
            }

            $video->update(['status' => VideoStatus::AssetsReady]);
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('video')->error('Asset collection failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
