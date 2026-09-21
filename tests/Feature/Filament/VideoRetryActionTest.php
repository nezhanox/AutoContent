<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Jobs\CollectVideoAssetsJob;
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
}
