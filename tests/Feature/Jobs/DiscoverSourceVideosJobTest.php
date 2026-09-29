<?php

namespace Tests\Feature\Jobs;

use App\Domain\Source\Providers\FakeYoutubeDownloader;
use App\Domain\Source\YoutubeDownloaderInterface;
use App\Jobs\DiscoverSourceVideosJob;
use App\Jobs\DownloadSourceVideoJob;
use App\Models\Enums\SourceVideoStatus;
use App\Models\SourceChannel;
use App\Models\SourceVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DiscoverSourceVideosJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function bindDownloader(array $videos, ?string $name = 'Chan Name'): void
    {
        $this->app->instance(
            YoutubeDownloaderInterface::class,
            (new FakeYoutubeDownloader)->respondWithVideos($videos)->respondWithChannelName($name)
        );
    }

    public function test_it_creates_only_new_videos_and_dispatches_download(): void
    {
        $channel = SourceChannel::factory()->create(['is_active' => true, 'name' => null, 'last_checked_at' => null]);
        SourceVideo::factory()->create(['source_channel_id' => $channel->id, 'youtube_id' => 'existing0001']);
        $this->bindDownloader([
            ['id' => 'existing0001', 'title' => 'Old', 'duration' => 100.0],
            ['id' => 'newvideo0002', 'title' => 'New', 'duration' => 250.5],
        ]);

        app()->call([new DiscoverSourceVideosJob($channel->id), 'handle']);

        $this->assertSame(2, SourceVideo::count());
        $new = SourceVideo::where('youtube_id', 'newvideo0002')->sole();
        $this->assertSame(SourceVideoStatus::Discovered, $new->status);
        $this->assertSame('New', $new->title);
        $this->assertEquals(250.5, $new->duration);
        $this->assertSame($channel->id, $new->source_channel_id);

        Queue::assertPushed(DownloadSourceVideoJob::class, 1);
        Queue::assertPushed(DownloadSourceVideoJob::class, fn ($job) => $job->sourceVideoId === $new->id);

        $channel->refresh();
        $this->assertSame('Chan Name', $channel->name);
        $this->assertNotNull($channel->last_checked_at);
    }

    public function test_it_keeps_an_existing_channel_name(): void
    {
        $channel = SourceChannel::factory()->create(['is_active' => true, 'name' => 'Mine']);
        $this->bindDownloader([]);

        app()->call([new DiscoverSourceVideosJob($channel->id), 'handle']);

        $this->assertSame('Mine', $channel->fresh()->name);
        $this->assertNotNull($channel->fresh()->last_checked_at);
        Queue::assertNothingPushed();
    }

    public function test_it_does_nothing_for_an_inactive_channel(): void
    {
        $channel = SourceChannel::factory()->create(['is_active' => false, 'last_checked_at' => null]);
        $this->bindDownloader([['id' => 'newvideo0002', 'title' => 'New', 'duration' => 10.0]]);

        app()->call([new DiscoverSourceVideosJob($channel->id), 'handle']);

        $this->assertSame(0, SourceVideo::count());
        $this->assertNull($channel->fresh()->last_checked_at);
        Queue::assertNothingPushed();
    }
}
