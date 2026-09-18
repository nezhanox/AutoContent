<?php

namespace App\Jobs;

use App\Domain\Video\Services\GenerateSubtitlesService;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateSubtitlesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;

    public int $timeout = 650;

    public int $tries = 3;

    public int $uniqueFor = 700;

    public function __construct(public readonly int $videoId)
    {
        $this->onQueue('whisper');
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

    public function handle(GenerateSubtitlesService $service): void
    {
        $video = Video::with(['voiceover', 'contentProject'])->findOrFail($this->videoId);

        if ($video->status !== VideoStatus::AssetsReady || $video->subtitle_id !== null || $video->voiceover === null) {
            return;
        }

        $result = $service->generate($video);

        $path = "projects/{$video->content_project_id}/subtitles/{$video->id}.srt";
        Storage::disk(config('filesystems.default'))->put($path, $result['srt']);

        DB::transaction(function () use ($video, $result, $path) {
            $subtitle = MediaAsset::create([
                'type' => MediaAssetType::Subtitle,
                'provider' => 'whisper',
                'path' => $path,
                'mime_type' => 'application/x-subrip',
                'metadata' => array_merge(
                    ['segments' => $result['segments'], 'language' => $result['language']],
                    $result['metadata']
                ),
                'hash' => hash('sha256', $result['srt']),
            ]);

            $video->update(['subtitle_id' => $subtitle->id]);
        });
    }

    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update(['status' => VideoStatus::Failed]);

        $this->notifyPermanentFailure('video', 'Subtitle generation failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
