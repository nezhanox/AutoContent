<?php

namespace App\Jobs;

use App\Domain\Source\Services\ClipSelector;
use App\Domain\Source\Support\Clip;
use App\Domain\Source\Support\Utterance;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\Enums\SourceVideoStatus;
use App\Models\SourceClip;
use App\Models\SourceVideo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

class SelectClipsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;

    public int $timeout = 600;

    public int $tries = 2;

    public int $uniqueFor = 700;

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
        return [30, 120];
    }

    public function handle(ClipSelector $selector): void
    {
        $video = SourceVideo::with('sourceChannel.contentProject')->findOrFail($this->sourceVideoId);

        if ($video->status !== SourceVideoStatus::Transcribed) {
            return;
        }

        $utterances = array_map(
            fn (array $u) => Utterance::fromArray($u),
            $video->transcript['utterances'] ?? []
        );

        $clips = $selector->select($video->sourceChannel, $video, $utterances);

        if ($clips === []) {
            $video->update(['status' => SourceVideoStatus::NoClips, 'error_message' => 'No suitable clips found']);

            return;
        }

        DB::transaction(function () use ($video, $clips) {
            $video->clips()->whereNull('video_id')->delete();

            /** @var Clip $clip */
            foreach ($clips as $clip) {
                SourceClip::create([
                    'source_video_id' => $video->id,
                    'start' => $clip->start,
                    'end' => $clip->end,
                    'title' => $clip->title,
                    'hook' => $clip->hook,
                    'score' => $clip->score,
                    'reason' => $clip->reason,
                ]);
            }

            $video->update(['status' => SourceVideoStatus::ClipsSelected]);
        });

        CreateClipVideosJob::dispatch($video->id);
    }

    public function failed(Throwable $exception): void
    {
        SourceVideo::whereKey($this->sourceVideoId)->update([
            'status' => SourceVideoStatus::Failed,
            'failed_stage' => 'select',
            'error_message' => $exception->getMessage(),
        ]);

        $this->notifyPermanentFailure('video', 'Clip selection failed permanently.', [
            'source_video_id' => $this->sourceVideoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
