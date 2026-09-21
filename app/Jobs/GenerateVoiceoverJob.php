<?php

namespace App\Jobs;

use App\Domain\Video\Services\GenerateVoiceoverService;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\Enums\VideoStatus;
use App\Models\Enums\VoiceoverStatus;
use App\Models\Video;
use App\Models\VideoScene;
use App\Models\Voiceover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateVoiceoverJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;

    public int $timeout = 400;

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

    public function handle(GenerateVoiceoverService $service): void
    {
        $video = Video::with('scenes')->findOrFail($this->videoId);

        if ($video->status !== VideoStatus::ScriptGenerated || $video->voiceover()->exists()) {
            return;
        }

        $result = $service->generate($video);

        $path = "projects/{$video->content_project_id}/audio/{$video->id}.mp3";
        Storage::disk(config('filesystems.default'))->put($path, $result['audio']);

        DB::transaction(function () use ($video, $result, $path) {
            Voiceover::create([
                'video_id' => $video->id,
                'provider' => $result['provider'],
                'voice' => $result['voice'],
                'text' => $result['text'],
                'file_path' => $path,
                'duration' => (int) round($result['duration']),
                'metadata' => $result['metadata'],
                'status' => VoiceoverStatus::Completed,
            ]);

            $this->rescaleSceneDurations($video, $result['duration']);

            $video->update(['status' => VideoStatus::VoiceGenerated]);
        });

        CollectVideoAssetsJob::dispatch($video->id);
    }

    /**
     * Scene durations come from the script-writing LLM's guess at narration
     * pacing, which can drift far from the TTS engine's actual speaking rate.
     * FfmpegVideoRenderer trims the final render to the sum of scene durations,
     * so without this rescale the end of the narration gets silently cut off.
     */
    private function rescaleSceneDurations(Video $video, float $actualDuration): void
    {
        $scenes = $video->scenes->sortBy('order')->values();
        $originalTotal = $scenes->sum('duration');

        if ($originalTotal <= 0 || $scenes->isEmpty()) {
            return;
        }

        $scaleFactor = $actualDuration / $originalTotal;
        $assigned = 0;
        $lastIndex = $scenes->count() - 1;

        foreach ($scenes as $index => $scene) {
            if ($index === $lastIndex) {
                $newDuration = max(1, (int) round($actualDuration) - $assigned);
            } else {
                $newDuration = max(1, (int) round($scene->duration * $scaleFactor));
                $assigned += $newDuration;
            }

            VideoScene::whereKey($scene->id)->update(['duration' => $newDuration]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update([
            'status' => VideoStatus::Failed,
            'failed_stage' => 'voiceover',
            'error_message' => $exception->getMessage(),
        ]);

        $this->notifyPermanentFailure('video', 'Voiceover generation failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
