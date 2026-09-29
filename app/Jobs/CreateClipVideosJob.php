<?php

namespace App\Jobs;

use App\Domain\Source\Support\SubtitleSlicer;
use App\Domain\Video\Support\SrtFormatter;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\SourceVideoStatus;
use App\Models\Enums\VideoSceneType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\SourceClip;
use App\Models\SourceVideo;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CreateClipVideosJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public int $uniqueFor = 400;

    public function __construct(public readonly int $sourceVideoId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->sourceVideoId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(): void
    {
        $source = SourceVideo::with('sourceChannel')->findOrFail($this->sourceVideoId);

        if ($source->status !== SourceVideoStatus::ClipsSelected) {
            return;
        }

        $slicer = new SubtitleSlicer((int) config('clips.subtitle_max_words'));
        $disk = Storage::disk(config('filesystems.default'));

        $clips = $source->clips()->whereNull('video_id')->orderBy('start')->get();

        foreach ($clips as $clip) {
            $video = DB::transaction(function () use ($source, $clip, $slicer, $disk) {
                // Re-check under lock so a concurrent run cannot create a second video.
                $clip = SourceClip::whereKey($clip->id)->lockForUpdate()->first();
                if ($clip === null || $clip->video_id !== null) {
                    return null;
                }

                return $this->createVideo($source, $clip, $slicer, $disk);
            });

            if ($video !== null) {
                RenderVideoJob::dispatch($video->id);
            }
        }

        $source->update(['status' => SourceVideoStatus::ClipsCreated]);
    }

    private function createVideo(SourceVideo $source, SourceClip $clip, SubtitleSlicer $slicer, $disk): Video
    {
        $projectId = $source->sourceChannel->content_project_id;

        $video = Video::create([
            'content_project_id' => $projectId,
            'content_idea_id' => null,
            'script_id' => null,
            'title' => $clip->title,
            'description' => $clip->hook ?? '',
            'status' => VideoStatus::AssetsReady,
            'source_clip_id' => $clip->id,
            'metadata' => [
                'source_video_id' => $source->id,
                'youtube_id' => $source->youtube_id,
                'clip_start' => $clip->start,
                'clip_end' => $clip->end,
            ],
        ]);

        VideoScene::create([
            'video_id' => $video->id,
            'order' => 0,
            'type' => VideoSceneType::Broll,
            'duration' => (int) max(1, round($clip->end - $clip->start)),
            'text' => $clip->title,
            'visual_query' => null,
            'asset_id' => null,
        ]);

        $segments = $slicer->slice($source->transcript['segments'] ?? [], $clip->start, $clip->end);
        $srt = SrtFormatter::format($segments);
        $path = "projects/{$projectId}/subtitles/{$video->id}.srt";
        $disk->put($path, $srt);

        $subtitle = MediaAsset::create([
            'type' => MediaAssetType::Subtitle,
            'provider' => 'whisper',
            'path' => $path,
            'mime_type' => 'application/x-subrip',
            'metadata' => ['segments' => $segments, 'language' => $source->transcript['language'] ?? null],
            'hash' => hash('sha256', $srt),
        ]);

        $video->update(['subtitle_id' => $subtitle->id]);
        $clip->update(['video_id' => $video->id]);

        return $video;
    }

    public function failed(Throwable $exception): void
    {
        SourceVideo::whereKey($this->sourceVideoId)->update([
            'status' => SourceVideoStatus::Failed,
            'failed_stage' => 'create',
            'error_message' => $exception->getMessage(),
        ]);

        $this->notifyPermanentFailure('video', 'Clip video creation failed permanently.', [
            'source_video_id' => $this->sourceVideoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
