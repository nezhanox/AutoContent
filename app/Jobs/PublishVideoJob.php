<?php

namespace App\Jobs;

use App\Domain\Publishing\SocialPublisherInterface;
use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NotifiesOnPermanentFailure, Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public int $uniqueFor = 150;

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
        return [15, 60, 120];
    }

    public function handle(SocialPublisherInterface $publisher): void
    {
        $publication = Publication::with('socialAccount')->findOrFail($this->publicationId);

        if ($publication->status !== PublicationStatus::Scheduled) {
            return;
        }

        $publication->update(['status' => PublicationStatus::Publishing]);

        $result = $publisher->publish($publication);

        DB::transaction(function () use ($publication, $result) {
            $publication->update([
                'status' => PublicationStatus::Published,
                'published_at' => now(),
                'external_post_id' => $result->externalPostId,
                'metadata' => array_merge($publication->metadata ?? [], $result->metadata),
            ]);
        });
    }

    public function failed(Throwable $exception): void
    {
        Publication::whereKey($this->publicationId)->update(['status' => PublicationStatus::Failed]);

        $this->notifyPermanentFailure('publishing', 'Publication failed permanently.', [
            'publication_id' => $this->publicationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
