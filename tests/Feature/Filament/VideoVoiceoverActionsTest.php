<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Jobs\GenerateVoiceoverJob;
use App\Models\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Models\Voiceover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class VideoVoiceoverActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_voiceover_action_dispatches_the_job_when_script_generated_and_no_voiceover_exists(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $video = Video::factory()->create(['status' => VideoStatus::ScriptGenerated]);

        Livewire::test(ListVideos::class)
            ->callTableAction('generateVoiceover', $video)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(GenerateVoiceoverJob::class, fn (GenerateVoiceoverJob $job) => $job->videoId === $video->id);
    }

    public function test_generate_voiceover_action_is_not_visible_before_scenes_are_generated(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::Draft]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('generateVoiceover', $video);
    }

    public function test_generate_voiceover_action_is_not_visible_once_a_voiceover_already_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::ScriptGenerated]);
        Voiceover::factory()->create(['video_id' => $video->id]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('generateVoiceover', $video);
    }
}
