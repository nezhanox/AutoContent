<?php

namespace App\Jobs;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Video\Services\GenerateScenesService;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\Enums\ScriptStatus;
use App\Models\Enums\VideoStatus;
use App\Models\Script;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateScenesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public int $uniqueFor = 200;

    public function __construct(public readonly int $scriptId) {}

    public function uniqueId(): string
    {
        return (string) $this->scriptId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(LlmManagerInterface $llmManager, GenerateScenesService $service): void
    {
        $script = Script::findOrFail($this->scriptId);

        if ($script->status !== ScriptStatus::Completed) {
            return;
        }

        $idea = $script->contentIdea;
        $target = $llmManager->resolve($idea->contentProject, 'script');

        $scenes = $service->generate($script, $target);

        DB::transaction(function () use ($script, $idea, $scenes) {
            $video = Video::firstOrCreate(
                ['script_id' => $script->id],
                [
                    'content_project_id' => $idea->content_project_id,
                    'content_idea_id' => $idea->id,
                    'title' => $script->metadata['title'] ?? $idea->title,
                    'description' => $script->hook ?? '',
                    'status' => VideoStatus::ScriptGenerated,
                ]
            );

            $video->scenes()->delete();

            foreach ($scenes as $order => $scene) {
                VideoScene::create([
                    'video_id' => $video->id,
                    'order' => $order,
                    'type' => $scene['type'],
                    'duration' => $scene['duration'],
                    'text' => $scene['text'],
                    'visual_query' => $scene['visual_query'],
                ]);
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        $video = Video::where('script_id', $this->scriptId)->first();

        if ($video !== null) {
            $video->update(['status' => VideoStatus::Failed]);
        }

        $this->notifyPermanentFailure('video', 'Scene generation failed permanently.', [
            'script_id' => $this->scriptId,
            'error' => $exception->getMessage(),
        ]);
    }
}
