<?php

namespace Tests\Feature\Jobs;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Source\Exceptions\ClipSelectionFailedException;
use App\Jobs\CreateClipVideosJob;
use App\Jobs\SelectClipsJob;
use App\Models\Enums\SourceChannelMode;
use App\Models\Enums\SourceVideoStatus;
use App\Models\SourceChannel;
use App\Models\SourceVideo;
use App\Models\User;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\QueuedLlmManager;
use Tests\TestCase;

class SelectClipsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function transcribed(SourceChannelMode $mode = SourceChannelMode::Highlights): SourceVideo
    {
        $channel = SourceChannel::factory()->create([
            'mode' => $mode, 'target_seconds' => 60, 'tolerance_seconds' => 15, 'max_clips' => 3, 'min_score' => 6,
        ]);
        $utterances = [];
        for ($i = 1; $i <= 12; $i++) {
            $utterances[] = ['id' => $i, 'start' => ($i - 1) * 10.0, 'end' => $i * 10.0, 'text' => "Sentence {$i}."];
        }

        return SourceVideo::factory()->create([
            'source_channel_id' => $channel->id,
            'duration' => 120,
            'status' => SourceVideoStatus::Transcribed,
            'transcript' => ['language' => 'en', 'segments' => $utterances, 'utterances' => $utterances],
        ]);
    }

    private function llm(array $responses): QueuedLlmManager
    {
        $llm = new QueuedLlmManager($responses);
        $this->app->instance(LlmManagerInterface::class, $llm);

        return $llm;
    }

    private function clipJson(int $start, int $end): array
    {
        return ['start_id' => $start, 'end_id' => $end, 'title' => "T{$start}", 'hook' => 'H', 'score' => 8, 'reason' => 'R'];
    }

    public function test_it_creates_source_clips_and_dispatches_create_job(): void
    {
        $this->llm([json_encode(['clips' => [$this->clipJson(1, 6)]])]);
        $video = $this->transcribed();

        app()->call([new SelectClipsJob($video->id), 'handle']);

        $video->refresh();
        $this->assertSame(SourceVideoStatus::ClipsSelected, $video->status);
        $this->assertCount(1, $video->clips);
        $clip = $video->clips->first();
        $this->assertSame('T1', $clip->title);
        $this->assertSame('H', $clip->hook);
        $this->assertSame(8, $clip->score);
        $this->assertSame('R', $clip->reason);
        $this->assertEqualsWithDelta(0.0, $clip->start, 0.001);
        $this->assertEqualsWithDelta(60.0, $clip->end, 0.001);
        Queue::assertPushed(CreateClipVideosJob::class, fn ($job) => $job->sourceVideoId === $video->id);
    }

    public function test_it_marks_no_clips_when_selection_is_empty(): void
    {
        $this->llm([json_encode(['clips' => []])]);
        $video = $this->transcribed();

        app()->call([new SelectClipsJob($video->id), 'handle']);

        $this->assertSame(SourceVideoStatus::NoClips, $video->refresh()->status);
        $this->assertCount(0, $video->clips);
        Queue::assertNotPushed(CreateClipVideosJob::class);
    }

    public function test_whole_mode_does_not_call_llm(): void
    {
        $llm = $this->llm([]);
        $video = $this->transcribed(SourceChannelMode::Whole);

        app()->call([new SelectClipsJob($video->id), 'handle']);

        $this->assertSame([], $llm->purposes);
        $this->assertSame(SourceVideoStatus::ClipsSelected, $video->refresh()->status);
        $this->assertCount(1, $video->clips);
        Queue::assertPushed(CreateClipVideosJob::class);
    }

    public function test_it_skips_videos_that_are_not_transcribed(): void
    {
        $this->llm([]);
        $video = $this->transcribed();
        $video->update(['status' => SourceVideoStatus::ClipsSelected]);

        app()->call([new SelectClipsJob($video->id), 'handle']);

        $this->assertCount(0, $video->clips);
        Queue::assertNothingPushed();
    }

    public function test_selector_failure_propagates_and_failed_marks_video(): void
    {
        Notification::fake();
        User::factory()->create();
        $this->llm(['nope', 'nope', 'nope']);
        $video = $this->transcribed();

        try {
            app()->call([new SelectClipsJob($video->id), 'handle']);
            $this->fail('Expected ClipSelectionFailedException');
        } catch (ClipSelectionFailedException $e) {
            (new SelectClipsJob($video->id))->failed($e);
        }

        $video->refresh();
        $this->assertSame(SourceVideoStatus::Failed, $video->status);
        $this->assertSame('select', $video->failed_stage);
        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $n): bool => $n->context['source_video_id'] === $video->id
        );
    }
}
