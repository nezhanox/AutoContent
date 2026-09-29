<?php

namespace App\Jobs;

use App\Domain\Source\Support\Utterance;
use App\Domain\Source\Support\UtteranceBuilder;
use App\Domain\Video\TranscriptionProviderInterface;
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

class TranscribeSourceVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;

    public int $timeout = 3700;

    public int $tries = 2;

    public int $uniqueFor = 3800;

    public function __construct(public readonly int $sourceVideoId)
    {
        $this->onQueue('whisper');
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

    public function handle(TranscriptionProviderInterface $transcriber): void
    {
        $video = SourceVideo::findOrFail($this->sourceVideoId);

        if ($video->status !== SourceVideoStatus::Downloaded || $video->file_path === null) {
            return;
        }

        $disk = Storage::disk(config('filesystems.default'));
        $base = sys_get_temp_dir().'/tr_'.uniqid();
        $sourcePath = $base.'.src';
        $audioPath = $base.'.wav';

        try {
            $in = $disk->readStream($video->file_path);
            if (! is_resource($in)) {
                throw new RuntimeException("Cannot read source video {$video->file_path}");
            }
            $out = fopen($sourcePath, 'wb');
            try {
                stream_copy_to_stream($in, $out);
            } finally {
                fclose($in);
                fclose($out);
            }

            $result = Process::timeout(3600)->run([
                config('render.ffmpeg_binary'), '-y', '-i', $sourcePath, '-vn', '-ac', '1', '-ar', '16000', $audioPath,
            ]);
            if ($result->failed()) {
                throw new RuntimeException('ffmpeg audio extraction failed: '.trim($result->errorOutput() ?: $result->output()));
            }

            $transcription = $transcriber->transcribe($audioPath, null);
        } finally {
            foreach ([$sourcePath, $audioPath] as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        }

        $segments = $transcription->segments;
        $utterances = (new UtteranceBuilder(
            (float) config('clips.utterance_pause_seconds'),
            (float) config('clips.utterance_max_seconds'),
        ))->build($segments);

        $transcript = [
            'language' => $transcription->language,
            'segments' => $segments,
            'utterances' => array_map(fn (Utterance $u) => $u->toArray(), $utterances),
        ];

        if ($utterances === []) {
            $video->update([
                'transcript' => $transcript,
                'status' => SourceVideoStatus::NoClips,
                'error_message' => 'No speech detected',
            ]);

            return;
        }

        $video->update(['transcript' => $transcript, 'status' => SourceVideoStatus::Transcribed]);

        SelectClipsJob::dispatch($video->id);
    }

    public function failed(Throwable $exception): void
    {
        SourceVideo::whereKey($this->sourceVideoId)->update([
            'status' => SourceVideoStatus::Failed,
            'failed_stage' => 'transcribe',
            'error_message' => $exception->getMessage(),
        ]);

        $this->notifyPermanentFailure('video', 'Source video transcription failed permanently.', [
            'source_video_id' => $this->sourceVideoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
