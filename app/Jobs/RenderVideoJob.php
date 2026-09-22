<?php

namespace App\Jobs;

use App\Domain\Video\VideoRendererInterface;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
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

        // CollectVideoAssetsService only ever leaves asset_id null for a scene
        // whose visual_query was blank (it skips those on purpose — see its
        // collect() loop) — any other null asset_id at this point would mean
        // asset collection is still incomplete, which AssetsReady already
        // rules out. So "no visual_query" is the one legitimate reason for a
        // scene to reach rendering without an asset (rendered as a solid
        // background instead), regardless of the scene's declared type.
        $scenesReady = $video->scenes->isNotEmpty()
            && $video->scenes->every(
                fn (VideoScene $scene): bool => $scene->asset_id !== null || blank($scene->visual_query)
            );

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

        QualityCheckVideoJob::dispatch($video->id);
    }

    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update([
            'status' => VideoStatus::Failed,
            'failed_stage' => 'render',
            'error_message' => $exception->getMessage(),
        ]);

        $this->notifyPermanentFailure('video', 'Video rendering failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
