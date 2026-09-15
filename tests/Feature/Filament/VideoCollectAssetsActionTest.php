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

class VideoCollectAssetsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_collect_assets_action_dispatches_the_job_when_voice_generated(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $video = Video::factory()->create(['status' => VideoStatus::VoiceGenerated]);

        Livewire::test(ListVideos::class)
            ->callTableAction('collectAssets', $video)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(CollectVideoAssetsJob::class, fn (CollectVideoAssetsJob $job) => $job->videoId === $video->id);
    }

    public function test_collect_assets_action_is_not_visible_before_a_voiceover_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::ScriptGenerated]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('collectAssets', $video);
    }

    public function test_collect_assets_action_is_not_visible_once_assets_are_ready(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('collectAssets', $video);
    }
}
