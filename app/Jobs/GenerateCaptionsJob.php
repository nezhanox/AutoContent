<?php

namespace App\Jobs;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Publishing\Services\GenerateCaptionsService;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

class GenerateCaptionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public int $uniqueFor = 200;

    public function __construct(public readonly int $publicationId) {}

    public function uniqueId(): string
    {
        return (string) $this->publicationId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(LlmManagerInterface $llmManager, GenerateCaptionsService $service): void
    {
        $publication = Publication::with(['video.contentProject', 'socialAccount'])->findOrFail($this->publicationId);

        if (! in_array($publication->status, [PublicationStatus::Draft, PublicationStatus::Scheduled], true) || $publication->caption !== null) {
            return;
        }

        $target = $llmManager->resolve($publication->video->contentProject, 'captions');

        $data = $service->generate($publication, $target);

        $publication->update([
            'caption' => $data['caption'],
            'hashtags' => $data['hashtags'],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Publication::whereKey($this->publicationId)->update([
            'error_message' => Str::limit($exception->getMessage(), 1000),
        ]);

        $this->notifyPermanentFailure('publishing', 'Caption generation failed permanently.', [
            'publication_id' => $this->publicationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
