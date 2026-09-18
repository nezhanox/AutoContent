<?php

namespace App\Jobs;

use App\Domain\Video\VideoRendererInterface;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

class RenderVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;

    public int $timeout = 900;

    public int $tries = 3;

    public int $uniqueFor = 950;

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
        return [30, 90, 180];
    }

    public function handle(VideoRendererInterface $renderer): void
    {
        $video = Video::with(['scenes.asset', 'voiceover', 'subtitle', 'musicAsset'])->findOrFail($this->videoId);

        $scenesReady = $video->scenes->isNotEmpty()
            && $video->scenes->every(fn (VideoScene $scene): bool => $scene->asset_id !== null);

        if ($video->status !== VideoStatus::AssetsReady || $video->subtitle_id === null
            || $video->voiceover === null || ! $scenesReady) {
            return;
        }

        $video->update(['status' => VideoStatus::Rendering]);

        $result = $renderer->render($video);

        DB::transaction(function () use ($video, $result) {
            $video->update([
                'file_path' => $result->path,
                'duration' => (int) round($result->duration),
                'width' => $result->width,
                'height' => $result->height,
                'status' => VideoStatus::Rendered,
            ]);
        });
    }

    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update(['status' => VideoStatus::Failed]);

        $this->notifyPermanentFailure('video', 'Video rendering failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
