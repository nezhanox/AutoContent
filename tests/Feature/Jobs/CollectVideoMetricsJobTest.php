<?php

namespace Tests\Feature\Jobs;

use App\Domain\Publishing\Providers\FakeSocialPublisher;
use App\Domain\Publishing\SocialPublisherInterface;
use App\Domain\Publishing\VideoMetricsResult;
use App\Jobs\CollectVideoMetricsJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use App\Models\VideoMetric;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CollectVideoMetricsJobTest extends TestCase
{
    use RefreshDatabase;

    private function bindFakePublisher(?VideoMetricsResult $result = null): void
    {
        $this->app->bind(SocialPublisherInterface::class, function () use ($result) {
            $fake = new FakeSocialPublisher;

            return $result !== null ? $fake->respondWithMetrics($result) : $fake;
        });
    }

    public function test_it_creates_a_video_metric_for_a_published_publication(): void
    {
        $this->bindFakePublisher(new VideoMetricsResult(views: 1000, likes: 100, comments: 10, shares: 5));

        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);

        app()->call([new CollectVideoMetricsJob($publication->id), 'handle']);

        $metric = VideoMetric::where('publication_id', $publication->id)->sole();

        $this->assertSame(1000, $metric->views);
        $this->assertSame(100, $metric->likes);
        $this->assertSame(10, $metric->comments);
        $this->assertSame(5, $metric->shares);
        $this->assertNotNull($metric->measured_at);
    }

    public function test_it_is_a_no_op_for_a_publication_that_is_not_published(): void
    {
        $this->bindFakePublisher();

        $publication = Publication::factory()->create(['status' => PublicationStatus::Scheduled]);

        app()->call([new CollectVideoMetricsJob($publication->id), 'handle']);

        $this->assertSame(0, VideoMetric::where('publication_id', $publication->id)->count());
    }

    public function test_repeated_runs_append_new_rows_instead_of_overwriting(): void
    {
        $this->bindFakePublisher(new VideoMetricsResult(views: 10, likes: 1, comments: 0, shares: 0));

        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);

        app()->call([new CollectVideoMetricsJob($publication->id), 'handle']);
        app()->call([new CollectVideoMetricsJob($publication->id), 'handle']);

        $this->assertSame(2, VideoMetric::where('publication_id', $publication->id)->count());
    }

    public function test_failed_sends_a_permanent_failure_notification(): void
    {
        Notification::fake();
        User::factory()->create();

        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);

        $job = new CollectVideoMetricsJob($publication->id);
        $job->failed(new \RuntimeException('API unavailable'));

        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->context['publication_id'] === $publication->id
        );
    }
}
