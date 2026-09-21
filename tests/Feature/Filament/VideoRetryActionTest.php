<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Jobs\CollectVideoAssetsJob;
use App\Jobs\RenderVideoJob;
use App\Models\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class VideoRetryActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_resets_status_and_redispatches_the_failed_stages_job(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $video = Video::factory()->create([
            'status' => VideoStatus::Failed,
            'failed_stage' => 'assets',
            'error_message' => 'boom',
        ]);

        Livewire::test(ListVideos::class)
            ->callTableAction('retry', $video)
            ->assertHasNoTableActionErrors();

        $fresh = $video->fresh();
        $this->assertSame(VideoStatus::VoiceGenerated, $fresh->status);
        $this->assertNull($fresh->failed_stage);
        $this->assertNull($fresh->error_message);

        Queue::assertPushed(CollectVideoAssetsJob::class, fn (CollectVideoAssetsJob $job) => $job->videoId === $video->id);
    }

    public function test_retry_action_is_not_visible_on_a_non_failed_video(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::Rendered]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('retry', $video);
    }

    public function test_retry_recovers_a_video_stranded_in_rendering_with_no_failed_stage(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $video = Video::factory()->create([
            'status' => VideoStatus::Rendering,
            'failed_stage' => null,
        ]);

        Livewire::test(ListVideos::class)
            ->assertTableActionVisible('retry', $video)
            ->callTableAction('retry', $video)
            ->assertHasNoTableActionErrors();

        $fresh = $video->fresh();
        $this->assertSame(VideoStatus::AssetsReady, $fresh->status);
        $this->assertNull($fresh->failed_stage);

        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job) => $job->videoId === $video->id);
    }

    public function test_retry_does_nothing_and_keeps_the_error_when_no_failed_stage_was_recorded(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $video = Video::factory()->create([
            'status' => VideoStatus::Failed,
            'failed_stage' => null,
            'error_message' => 'some old error',
        ]);

        Livewire::test(ListVideos::class)
            ->callTableAction('retry', $video)
            ->assertHasNoTableActionErrors();

        $fresh = $video->fresh();
        $this->assertSame(VideoStatus::Failed, $fresh->status);
        $this->assertNull($fresh->failed_stage);
        $this->assertSame('some old error', $fresh->error_message);

        Queue::assertNothingPushed();
    }

    public function test_the_stage_column_is_red_for_a_failed_quality_check(): void
    {
        $this->actingAs(User::factory()->create());

        $failed = Video::factory()->create([
            'status' => VideoStatus::Rendered,
            'quality_passed' => false,
            'quality_report' => ['checks' => ['has_audio_stream' => false]],
        ]);
        $passed = Video::factory()->create([
            'status' => VideoStatus::Rendered,
            'quality_passed' => true,
            'quality_report' => ['checks' => ['has_audio_stream' => true]],
        ]);

        $column = Livewire::test(ListVideos::class)->instance()->getTable()->getColumn('stage');

        $this->assertSame('danger', $column->record($failed)->getColor($failed->currentStageLabel()));
        $this->assertSame('success', $column->record($passed)->getColor($passed->currentStageLabel()));
    }
}
