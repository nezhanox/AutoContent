<?php

namespace Tests\Feature\Console;

use App\Jobs\DiscoverSourceVideosJob;
use App\Models\SourceChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PollSourceChannelsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_it_dispatches_discovery_only_for_active_channels(): void
    {
        $active = SourceChannel::factory()->create(['is_active' => true]);
        SourceChannel::factory()->create(['is_active' => false]);

        $this->artisan('source:poll')
            ->expectsOutputToContain('Dispatched 1 discovery job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(DiscoverSourceVideosJob::class, 1);
        Queue::assertPushed(DiscoverSourceVideosJob::class, fn (DiscoverSourceVideosJob $job): bool => $job->channelId === $active->id);
    }

    public function test_it_dispatches_only_the_requested_channel(): void
    {
        SourceChannel::factory()->create();
        $target = SourceChannel::factory()->create();

        $this->artisan('source:poll', ['--channel' => $target->id])
            ->expectsOutputToContain('Dispatched 1 discovery job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(DiscoverSourceVideosJob::class, 1);
        Queue::assertPushed(DiscoverSourceVideosJob::class, fn (DiscoverSourceVideosJob $job): bool => $job->channelId === $target->id);
    }

    public function test_it_dispatches_nothing_when_there_are_no_channels(): void
    {
        $this->artisan('source:poll')
            ->expectsOutputToContain('Dispatched 0 discovery job(s).')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
