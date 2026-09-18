<?php

namespace Tests\Feature\Models;

use App\Models\Publication;
use App\Models\VideoMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoMetricTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_per_publication_returns_only_the_most_recent_row_per_publication(): void
    {
        $publicationA = Publication::factory()->create();
        $publicationB = Publication::factory()->create();

        VideoMetric::factory()->create([
            'publication_id' => $publicationA->id,
            'views' => 100,
            'measured_at' => now()->subHour(),
        ]);
        $latestA = VideoMetric::factory()->create([
            'publication_id' => $publicationA->id,
            'views' => 200,
            'measured_at' => now(),
        ]);
        $latestB = VideoMetric::factory()->create([
            'publication_id' => $publicationB->id,
            'views' => 50,
            'measured_at' => now(),
        ]);

        $results = VideoMetric::latestPerPublication()->get();

        $this->assertCount(2, $results);
        $this->assertEqualsCanonicalizing(
            [$latestA->id, $latestB->id],
            $results->pluck('id')->all()
        );
    }
}
