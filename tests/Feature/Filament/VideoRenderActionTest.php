<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Jobs\RenderVideoJob;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class VideoRenderActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_video_action_dispatches_the_job_when_assets_ready_and_subtitle_exists(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake();

        $subtitle = MediaAsset::factory()->create(['type' => MediaAssetType::Subtitle]);
        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady, 'subtitle_id' => $subtitle->id]);

        Livewire::test(ListVideos::class)
            ->callTableAction('renderVideo', $video)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job) => $job->videoId === $video->id);
    }

    public function test_render_video_action_is_not_visible_without_a_subtitle(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady, 'subtitle_id' => null]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('renderVideo', $video);
    }

    public function test_render_video_action_is_not_visible_before_assets_are_ready(): void
    {
        $this->actingAs(User::factory()->create());

        $subtitle = MediaAsset::factory()->create(['type' => MediaAssetType::Subtitle]);
        $video = Video::factory()->create(['status' => VideoStatus::VoiceGenerated, 'subtitle_id' => $subtitle->id]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('renderVideo', $video);
    }
}
