<?php

namespace Tests\Feature\Console;

use App\Jobs\PublishVideoJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchDuePublicationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_publish_video_job_only_for_due_scheduled_publications(): void
    {
        Queue::fake();

        $due = Publication::factory()->create([
            'status' => PublicationStatus::Scheduled,
            'scheduled_at' => now()->subMinute(),
        ]);

        Publication::factory()->create([
            'status' => PublicationStatus::Scheduled,
            'scheduled_at' => now()->addHour(),
        ]);

        Publication::factory()->create([
            'status' => PublicationStatus::Draft,
            'scheduled_at' => null,
        ]);

        Artisan::call('publications:dispatch-due');

        Queue::assertPushed(PublishVideoJob::class, 1);
        Queue::assertPushed(PublishVideoJob::class, fn (PublishVideoJob $job): bool => $job->publicationId === $due->id);
    }
}
