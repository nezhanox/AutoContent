<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Jobs\QualityCheckVideoJob;
use App\Models\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class VideoCheckQualityActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_quality_action_dispatches_the_job_when_rendered(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake();

        $video = Video::factory()->create(['status' => VideoStatus::Rendered]);

        Livewire::test(ListVideos::class)
            ->callTableAction('checkQuality', $video)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(QualityCheckVideoJob::class, fn (QualityCheckVideoJob $job) => $job->videoId === $video->id);
    }

    public function test_check_quality_action_is_not_visible_before_rendering(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('checkQuality', $video);
    }
}
