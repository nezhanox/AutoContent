<?php

namespace Tests\Feature\Console;

use App\Jobs\CollectVideoMetricsJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CollectMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_collect_video_metrics_job_only_for_published_publications(): void
    {
        Queue::fake();

        $published = Publication::factory()->create(['status' => PublicationStatus::Published]);
        Publication::factory()->create(['status' => PublicationStatus::Scheduled]);
        Publication::factory()->create(['status' => PublicationStatus::Draft]);

        Artisan::call('metrics:collect');

        Queue::assertPushed(CollectVideoMetricsJob::class, 1);
        Queue::assertPushed(CollectVideoMetricsJob::class, fn (CollectVideoMetricsJob $job): bool => $job->publicationId === $published->id);
    }
}
