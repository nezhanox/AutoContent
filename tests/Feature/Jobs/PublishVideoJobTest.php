<?php

namespace Tests\Feature\Jobs;

use App\Domain\Publishing\Providers\FakeSocialPublisher;
use App\Domain\Publishing\PublishResult;
use App\Domain\Publishing\SocialPublisherInterface;
use App\Jobs\PublishVideoJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PublishVideoJobTest extends TestCase
{
    use RefreshDatabase;

    private function bindFakePublisher(?PublishResult $result = null): void
    {
        $this->app->bind(SocialPublisherInterface::class, function () use ($result) {
            $fake = new FakeSocialPublisher;

            return $result !== null ? $fake->respondWith($result) : $fake;
        });
    }

    public function test_it_publishes_and_updates_the_publication(): void
    {
        $this->bindFakePublisher(new PublishResult(externalPostId: 'ext-1', metadata: ['url' => 'https://example.com/1']));

        $publication = Publication::factory()->create(['status' => PublicationStatus::Scheduled]);

        app()->call([new PublishVideoJob($publication->id), 'handle']);

        $fresh = $publication->fresh();
        $this->assertSame(PublicationStatus::Published, $fresh->status);
        $this->assertSame('ext-1', $fresh->external_post_id);
        $this->assertSame('https://example.com/1', $fresh->metadata['url']);
        $this->assertNotNull($fresh->published_at);
    }

    public function test_it_is_a_no_op_when_the_publication_is_not_scheduled(): void
    {
        $this->bindFakePublisher();

        $publication = Publication::factory()->create(['status' => PublicationStatus::Draft]);

        app()->call([new PublishVideoJob($publication->id), 'handle']);

        $this->assertSame(PublicationStatus::Draft, $publication->fresh()->status);
        $this->assertNull($publication->fresh()->external_post_id);
    }

    public function test_calling_handle_twice_does_not_publish_twice(): void
    {
        $this->bindFakePublisher(new PublishResult(externalPostId: 'ext-1'));

        $publication = Publication::factory()->create(['status' => PublicationStatus::Scheduled]);

        app()->call([new PublishVideoJob($publication->id), 'handle']);
        app()->call([new PublishVideoJob($publication->id), 'handle']);

        $this->assertSame('ext-1', $publication->fresh()->external_post_id);
        $this->assertSame(PublicationStatus::Published, $publication->fresh()->status);
    }

    public function test_failed_marks_the_publication_failed_and_sends_a_notification(): void
    {
        Notification::fake();
        User::factory()->create();

        $publication = Publication::factory()->create(['status' => PublicationStatus::Publishing]);

        $job = new PublishVideoJob($publication->id);
        $job->failed(new \RuntimeException('API unavailable'));

        $this->assertSame(PublicationStatus::Failed, $publication->fresh()->status);

        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->context['publication_id'] === $publication->id
        );
    }
}
