<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Jobs\GenerateSubtitlesJob;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class VideoGenerateSubtitlesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_subtitles_action_dispatches_the_job_when_assets_ready_and_no_subtitle_exists(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady]);

        Livewire::test(ListVideos::class)
            ->callTableAction('generateSubtitles', $video)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(GenerateSubtitlesJob::class, fn (GenerateSubtitlesJob $job) => $job->videoId === $video->id);
    }

    public function test_generate_subtitles_action_is_not_visible_before_assets_are_ready(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::VoiceGenerated]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('generateSubtitles', $video);
    }

    public function test_generate_subtitles_action_is_not_visible_once_a_subtitle_already_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $subtitle = MediaAsset::factory()->create(['type' => MediaAssetType::Subtitle]);
        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady, 'subtitle_id' => $subtitle->id]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('generateSubtitles', $video);
    }
}
