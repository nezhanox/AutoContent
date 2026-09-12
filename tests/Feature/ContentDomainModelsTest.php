<?php

namespace Tests\Feature;

use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\Enums\ScriptStatus;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentDomainModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_project_has_many_content_ideas(): void
    {
        $project = ContentProject::factory()->create();
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);

        $this->assertTrue($project->contentIdeas->contains($idea));
        $this->assertTrue($idea->contentProject->is($project));
    }

    public function test_content_idea_status_casts_to_enum(): void
    {
        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::Approved]);

        $this->assertSame(ContentIdeaStatus::Approved, $idea->fresh()->status);
    }

    public function test_script_belongs_to_content_idea_and_deletes_with_it(): void
    {
        $idea = ContentIdea::factory()->create();
        $script = Script::factory()->create(['content_idea_id' => $idea->id]);

        $this->assertTrue($script->contentIdea->is($idea));

        $idea->delete();

        $this->assertDatabaseMissing('scripts', ['id' => $script->id]);
    }

    public function test_script_status_casts_to_enum(): void
    {
        $script = Script::factory()->create(['status' => ScriptStatus::Failed]);

        $this->assertSame(ScriptStatus::Failed, $script->fresh()->status);
    }

    public function test_content_project_settings_cast_to_array(): void
    {
        $project = ContentProject::factory()->create();

        $this->assertIsArray($project->fresh()->settings);
        $this->assertArrayHasKey('ai', $project->settings);
    }
}
