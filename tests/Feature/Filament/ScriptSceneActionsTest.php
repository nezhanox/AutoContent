<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Scripts\Pages\ListScripts;
use App\Jobs\GenerateScenesJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ScriptStatus;
use App\Models\Script;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ScriptSceneActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_scenes_action_dispatches_the_job_when_completed_and_no_video_exists(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $script = Script::factory()->create(['status' => ScriptStatus::Completed]);

        Livewire::test(ListScripts::class)
            ->callTableAction('generateScenes', $script)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(GenerateScenesJob::class, fn (GenerateScenesJob $job) => $job->scriptId === $script->id);
    }

    public function test_generate_scenes_action_is_not_visible_for_an_incomplete_script(): void
    {
        $this->actingAs(User::factory()->create());

        $script = Script::factory()->create(['status' => ScriptStatus::Processing]);

        Livewire::test(ListScripts::class)
            ->assertTableActionHidden('generateScenes', $script);
    }

    public function test_generate_scenes_action_is_not_visible_once_a_video_already_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create();
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);
        $script = Script::factory()->create(['content_idea_id' => $idea->id, 'status' => ScriptStatus::Completed]);

        Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'script_id' => $script->id,
        ]);

        Livewire::test(ListScripts::class)
            ->assertTableActionHidden('generateScenes', $script);
    }
}
