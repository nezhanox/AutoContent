<?php

namespace Tests\Unit\Domain\Publishing;

use App\Domain\Publishing\Providers\FakeSocialPublisher;
use App\Domain\Publishing\VideoMetricsResult;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\VideoMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FakeSocialPublisherMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_grows_metrics_monotonically_from_the_previous_measurement(): void
    {
        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);
        VideoMetric::factory()->create([
            'publication_id' => $publication->id,
            'views' => 1000,
            'likes' => 100,
            'comments' => 10,
            'shares' => 5,
            'measured_at' => now()->subHour(),
        ]);

        $result = (new FakeSocialPublisher)->fetchMetrics($publication->fresh());

        $this->assertInstanceOf(VideoMetricsResult::class, $result);
        $this->assertGreaterThan(1000, $result->views);
        $this->assertGreaterThan(100, $result->likes);
        $this->assertGreaterThanOrEqual(10, $result->comments);
        $this->assertGreaterThanOrEqual(5, $result->shares);
    }

    public function test_it_starts_from_a_positive_baseline_when_there_is_no_previous_measurement(): void
    {
        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);

        $result = (new FakeSocialPublisher)->fetchMetrics($publication);

        $this->assertGreaterThan(0, $result->views);
        $this->assertGreaterThan(0, $result->likes);
    }

    public function test_respond_with_metrics_overrides_the_generated_result(): void
    {
        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);
        $fixed = new VideoMetricsResult(views: 42, likes: 7, comments: 1, shares: 0);

        $result = (new FakeSocialPublisher)->respondWithMetrics($fixed)->fetchMetrics($publication);

        $this->assertSame($fixed, $result);
    }
}
