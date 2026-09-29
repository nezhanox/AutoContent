<?php

namespace App\Jobs;

use App\Domain\Source\YoutubeDownloaderInterface;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\Enums\SourceVideoStatus;
use App\Models\SourceVideo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DownloadSourceVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;

    public int $timeout = 3700;

    public int $tries = 2;

    public int $uniqueFor = 3800;

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
        return [60, 300];
    }

    public function handle(YoutubeDownloaderInterface $downloader): void
    {
        $video = SourceVideo::with('sourceChannel')->findOrFail($this->sourceVideoId);

        // Downloading is accepted too so that a queue retry after a crash mid-download is not a silent no-op.
        if (! in_array($video->status, [SourceVideoStatus::Discovered, SourceVideoStatus::Downloading], true)) {
            return;
        }

        $maxSeconds = $video->sourceChannel->max_source_minutes * 60;

        if ($video->duration > 0 && $video->duration > $maxSeconds) {
            $this->skip($video);

            return;
        }

        $video->update(['status' => SourceVideoStatus::Downloading]);

        $tmp = sys_get_temp_dir().'/src_'.uniqid().'.mp4';

        try {
            $downloader->download($video->youtube_id, $tmp);

            if ($video->duration <= 0) {
                $video->update(['duration' => $this->probeDuration($tmp)]);

                if ($video->duration > $maxSeconds) {
                    $this->skip($video);

                    return;
                }
            }

            $path = "source/{$video->source_channel_id}/{$video->youtube_id}.mp4";
            $stream = fopen($tmp, 'rb');
            try {
                Storage::disk(config('filesystems.default'))->put($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }

        $video->update(['file_path' => $path, 'status' => SourceVideoStatus::Downloaded]);

        TranscribeSourceVideoJob::dispatch($video->id);
    }

    private function skip(SourceVideo $video): void
    {
        $video->update([
            'status' => SourceVideoStatus::Skipped,
            'error_message' => 'Longer than max_source_minutes',
        ]);
    }

    private function probeDuration(string $path): float
    {
        $result = Process::timeout((int) config('render.timeout'))->run([
            config('render.ffprobe_binary'), '-v', 'quiet', '-print_format', 'json', '-show_format', $path,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('ffprobe failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        $data = json_decode($result->output(), true);

        return (float) ($data['format']['duration'] ?? 0);
    }

    public function failed(Throwable $exception): void
    {
        SourceVideo::whereKey($this->sourceVideoId)->update([
            'status' => SourceVideoStatus::Failed,
            'failed_stage' => 'download',
            'error_message' => $exception->getMessage(),
        ]);

        $this->notifyPermanentFailure('video', 'Source video download failed permanently.', [
            'source_video_id' => $this->sourceVideoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
