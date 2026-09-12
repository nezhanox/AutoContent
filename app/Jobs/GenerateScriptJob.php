<?php

namespace App\Jobs;

use App\Domain\Content\Services\GenerateScriptService;
use App\Domain\Llm\LlmManagerInterface;
use App\Models\ContentIdea;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\Enums\ScriptStatus;
use App\Models\Script;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateScriptJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public int $uniqueFor = 200;

    public function __construct(public readonly int $contentIdeaId) {}

    public function uniqueId(): string
    {
        return (string) $this->contentIdeaId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(LlmManagerInterface $llmManager, GenerateScriptService $service): void
    {
        $idea = ContentIdea::findOrFail($this->contentIdeaId);

        if (! in_array($idea->status, [ContentIdeaStatus::Approved, ContentIdeaStatus::Processing], true)) {
            return;
        }

        if ($idea->status !== ContentIdeaStatus::Processing) {
            $idea->update(['status' => ContentIdeaStatus::Processing]);
        }

        $target = $llmManager->resolve($idea->contentProject, 'script');

        $script = Script::firstOrCreate(
            ['content_idea_id' => $idea->id],
            [
                'provider' => $target->providerName,
                'model' => $target->model,
                'prompt_version' => 'v1',
                'status' => ScriptStatus::Pending,
            ]
        );

        $script->update(['status' => ScriptStatus::Processing]);

        $data = $service->generate($idea, $target);

        $script->update([
            'content' => $data['script'],
            'hook' => $data['hook'],
            'estimated_duration' => $data['estimated_duration'],
            'metadata' => ['title' => $data['title'], 'cta' => $data['cta']],
            'status' => ScriptStatus::Completed,
        ]);

        $idea->update(['status' => ContentIdeaStatus::Used]);
    }

    public function failed(Throwable $exception): void
    {
        $idea = ContentIdea::find($this->contentIdeaId);

        if ($idea === null) {
            return;
        }

        $script = Script::where('content_idea_id', $idea->id)->first();

        if ($script !== null) {
            $script->update([
                'status' => ScriptStatus::Failed,
                'metadata' => array_merge($script->metadata ?? [], ['error' => $exception->getMessage()]),
            ]);
        }

        if ($idea->status === ContentIdeaStatus::Processing) {
            $idea->update(['status' => ContentIdeaStatus::Approved]);
        }

        Log::channel('content')->error('Script generation failed permanently.', [
            'content_idea_id' => $idea->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
