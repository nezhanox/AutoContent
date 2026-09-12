<?php

namespace App\Jobs;

use App\Domain\Video\Services\GenerateVoiceoverService;
use App\Models\Enums\VideoStatus;
use App\Models\Enums\VoiceoverStatus;
use App\Models\Video;
use App\Models\Voiceover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateVoiceoverJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 180;

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
                'metadata' => $result['metadata'],
                'status' => VoiceoverStatus::Completed,
            ]);

            $video->update(['status' => VideoStatus::VoiceGenerated]);
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('video')->error('Voiceover generation failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
