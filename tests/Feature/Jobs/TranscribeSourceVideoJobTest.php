<?php

namespace Tests\Feature\Jobs;

use App\Domain\Video\Providers\FakeTranscriptionProvider;
use App\Domain\Video\TranscriptionProviderInterface;
use App\Jobs\SelectClipsJob;
use App\Jobs\TranscribeSourceVideoJob;
use App\Models\Enums\SourceVideoStatus;
use App\Models\SourceVideo;
use App\Models\User;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TranscribeSourceVideoJobTest extends TestCase
{
    use RefreshDatabase;

    private FakeTranscriptionProvider $transcriber;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake(config('filesystems.default'));
        Process::fake();
        $this->transcriber = new FakeTranscriptionProvider;
        $this->app->instance(TranscriptionProviderInterface::class, $this->transcriber);
    }

    private function downloadedVideo(array $attributes = []): SourceVideo
    {
        $video = SourceVideo::factory()->create($attributes + [
            'status' => SourceVideoStatus::Downloaded,
            'file_path' => 'source/1/abc.mp4',
        ]);
        Storage::disk(config('filesystems.default'))->put('source/1/abc.mp4', 'fake-source-video');

        return $video;
    }

    public function test_it_transcribes_and_dispatches_select_clips(): void
    {
        $segments = [
            ['start' => 0.0, 'end' => 2.0, 'text' => 'Hello there.'],
            ['start' => 3.0, 'end' => 5.0, 'text' => 'Second sentence.'],
        ];
        $this->transcriber->respondWith($segments, 'uk');
        $video = $this->downloadedVideo();

        app()->call([new TranscribeSourceVideoJob($video->id), 'handle']);

        $video->refresh();
        $this->assertSame(SourceVideoStatus::Transcribed, $video->status);
        $this->assertSame('uk', $video->transcript['language']);
        $this->assertEquals($segments, $video->transcript['segments']);
        $this->assertCount(2, $video->transcript['utterances']);
        $this->assertEquals(
            ['id' => 1, 'start' => 0.0, 'end' => 2.0, 'text' => 'Hello there.'],
            $video->transcript['utterances'][0]
        );
        $this->assertNull($this->transcriber->lastLanguageArgument);
        Process::assertRan(fn ($process) => in_array('-vn', $process->command) && in_array('16000', $process->command));
        Queue::assertPushed(SelectClipsJob::class, fn ($job) => $job->sourceVideoId === $video->id);
    }

    public function test_it_marks_no_clips_when_there_is_no_speech(): void
    {
        $this->transcriber->respondWith([], 'en');
        $video = $this->downloadedVideo();

        app()->call([new TranscribeSourceVideoJob($video->id), 'handle']);

        $video->refresh();
        $this->assertSame(SourceVideoStatus::NoClips, $video->status);
        $this->assertSame('No speech detected', $video->error_message);
        Queue::assertNothingPushed();
    }

    public function test_it_is_a_no_op_when_status_is_not_downloaded(): void
    {
        $this->transcriber->respondWith([['start' => 0.0, 'end' => 1.0, 'text' => 'Hi.']]);
        $video = $this->downloadedVideo(['status' => SourceVideoStatus::Discovered]);

        app()->call([new TranscribeSourceVideoJob($video->id), 'handle']);

        $this->assertNull($this->transcriber->lastAudioPath);
        $this->assertSame(SourceVideoStatus::Discovered, $video->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_failed_marks_the_video_failed_and_sends_a_notification(): void
    {
        Notification::fake();
        User::factory()->create();
        $video = $this->downloadedVideo();

        (new TranscribeSourceVideoJob($video->id))->failed(new \RuntimeException('boom'));

        $video->refresh();
        $this->assertSame(SourceVideoStatus::Failed, $video->status);
        $this->assertSame('transcribe', $video->failed_stage);
        $this->assertSame('boom', $video->error_message);
        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $n): bool => $n->context['source_video_id'] === $video->id
        );
    }
}
