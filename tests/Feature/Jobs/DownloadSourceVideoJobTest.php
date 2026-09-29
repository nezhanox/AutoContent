<?php

namespace Tests\Feature\Jobs;

use App\Domain\Source\Providers\FakeYoutubeDownloader;
use App\Domain\Source\YoutubeDownloaderInterface;
use App\Jobs\DownloadSourceVideoJob;
use App\Jobs\TranscribeSourceVideoJob;
use App\Models\Enums\SourceVideoStatus;
use App\Models\SourceChannel;
use App\Models\SourceVideo;
use App\Models\User;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DownloadSourceVideoJobTest extends TestCase
{
    use RefreshDatabase;

    private FakeYoutubeDownloader $downloader;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake(config('filesystems.default'));
        $this->downloader = new FakeYoutubeDownloader;
        $this->app->instance(YoutubeDownloaderInterface::class, $this->downloader);
    }

    private function makeVideo(array $attributes = [], array $channel = ['max_source_minutes' => 120]): SourceVideo
    {
        $sourceChannel = SourceChannel::factory()->create($channel);

        return SourceVideo::factory()->create($attributes + [
            'source_channel_id' => $sourceChannel->id,
            'youtube_id' => 'abcdefghijk',
            'duration' => 600,
        ]);
    }

    public function test_it_downloads_stores_and_dispatches_transcribe(): void
    {
        $video = $this->makeVideo();

        app()->call([new DownloadSourceVideoJob($video->id), 'handle']);

        $video->refresh();
        $path = "source/{$video->source_channel_id}/abcdefghijk.mp4";
        $this->assertSame(SourceVideoStatus::Downloaded, $video->status);
        $this->assertSame($path, $video->file_path);
        Storage::disk(config('filesystems.default'))->assertExists($path);
        $this->assertSame('fake-source-video', Storage::disk(config('filesystems.default'))->get($path));
        $this->assertSame(['abcdefghijk'], $this->downloader->downloaded);
        Queue::assertPushed(TranscribeSourceVideoJob::class, fn ($job) => $job->sourceVideoId === $video->id);
    }

    public function test_it_skips_videos_longer_than_the_limit_without_downloading(): void
    {
        $video = $this->makeVideo(['duration' => 61 * 60], ['max_source_minutes' => 60]);

        app()->call([new DownloadSourceVideoJob($video->id), 'handle']);

        $video->refresh();
        $this->assertSame(SourceVideoStatus::Skipped, $video->status);
        $this->assertSame('Longer than max_source_minutes', $video->error_message);
        $this->assertSame([], $this->downloader->downloaded);
        Queue::assertNothingPushed();
    }

    public function test_it_probes_the_duration_when_unknown_and_skips_when_too_long(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode(['format' => ['duration' => '7200.5']])),
        ]);
        $video = $this->makeVideo(['duration' => 0], ['max_source_minutes' => 60]);

        app()->call([new DownloadSourceVideoJob($video->id), 'handle']);

        $video->refresh();
        $this->assertSame(SourceVideoStatus::Skipped, $video->status);
        $this->assertEquals(7200.5, $video->duration);
        $this->assertNull($video->file_path);
        Queue::assertNothingPushed();
    }

    public function test_it_stores_the_probed_duration_when_within_the_limit(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode(['format' => ['duration' => '300.0']])),
        ]);
        $video = $this->makeVideo(['duration' => 0]);

        app()->call([new DownloadSourceVideoJob($video->id), 'handle']);

        $video->refresh();
        $this->assertSame(SourceVideoStatus::Downloaded, $video->status);
        $this->assertEquals(300.0, $video->duration);
        Queue::assertPushed(TranscribeSourceVideoJob::class);
    }

    public function test_it_is_a_no_op_when_status_is_not_discovered(): void
    {
        $video = $this->makeVideo(['status' => SourceVideoStatus::Downloaded]);

        app()->call([new DownloadSourceVideoJob($video->id), 'handle']);

        $this->assertSame([], $this->downloader->downloaded);
        $this->assertSame(SourceVideoStatus::Downloaded, $video->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_failed_marks_the_video_failed_and_sends_a_notification(): void
    {
        Notification::fake();
        User::factory()->create();
        $video = $this->makeVideo();

        (new DownloadSourceVideoJob($video->id))->failed(new \RuntimeException('boom'));

        $video->refresh();
        $this->assertSame(SourceVideoStatus::Failed, $video->status);
        $this->assertSame('download', $video->failed_stage);
        $this->assertSame('boom', $video->error_message);
        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $n): bool => $n->context['source_video_id'] === $video->id
        );
    }

    public function test_it_runs_on_the_worker_only_source_queue(): void
    {
        $this->assertSame('source', (new DownloadSourceVideoJob(1))->queue);
    }

    public function test_it_throws_when_the_storage_write_fails(): void
    {
        $video = $this->makeVideo();
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->andReturn($disk);

        try {
            app()->call([new DownloadSourceVideoJob($video->id), 'handle']);
            $this->fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to store', $e->getMessage());
        }

        $this->assertNull($video->fresh()->file_path);
        Queue::assertNothingPushed();
    }
}
