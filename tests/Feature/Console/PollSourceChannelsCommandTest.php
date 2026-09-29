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

    public function test_it_rejects_a_non_positive_integer_channel_option(): void
    {
        SourceChannel::factory()->create();

        foreach (['abc', '0', '-1', '1.5'] as $value) {
            $this->artisan('source:poll', ['--channel' => $value])
                ->expectsOutputToContain('--channel')
                ->assertExitCode(1);
        }

        Queue::assertNothingPushed();
    }

    public function test_it_warns_when_the_requested_channel_is_unknown_or_inactive(): void
    {
        $inactive = SourceChannel::factory()->create(['is_active' => false]);

        foreach ([9999, $inactive->id] as $id) {
            $this->artisan('source:poll', ['--channel' => $id])
                ->expectsOutputToContain('No active source channel matched')
                ->expectsOutputToContain('Dispatched 0 discovery job(s).')
                ->assertExitCode(0);
        }

        Queue::assertNothingPushed();
    }
}
