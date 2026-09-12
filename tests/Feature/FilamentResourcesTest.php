<?php

namespace Tests\Feature;

use App\Models\LlmUsageLog;
use App\Models\MediaAsset;
use App\Models\Publication;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoMetric;
use App\Models\VideoScene;
use App\Models\Voiceover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentResourcesTest extends TestCase
{
    use RefreshDatabase;

    private array $resourceSlugs = [
        'content-projects', 'content-ideas', 'scripts', 'videos', 'video-scenes',
        'media-assets', 'voiceovers', 'social-accounts', 'publications', 'video-metrics',
        'llm-usage-logs',
    ];

    public function test_every_resource_index_page_is_reachable_by_an_admin(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->seedOneRowPerResource();

        foreach ($this->resourceSlugs as $slug) {
            $response = $this->get("/admin/{$slug}");

            $response->assertSuccessful();
        }
    }

    /**
     * Give every resource's table at least one real row, so the index pages have to
     * actually render their columns (enum casts, relation columns, JSON columns)
     * rather than short-circuiting on an empty-state placeholder.
     *
     * Factories cascade-create their required parents, so a handful of leaf models
     * cover most of the graph; the rest are created explicitly.
     */
    private function seedOneRowPerResource(): void
    {
        // Video pulls in ContentProject, ContentIdea and Script.
        $video = Video::factory()->create();

        VideoScene::factory()->create(['video_id' => $video->id]);
        Voiceover::factory()->create(['video_id' => $video->id]);
        MediaAsset::factory()->create();

        // Publication pulls in a SocialAccount (and its own ContentProject).
        $publication = Publication::factory()->create(['video_id' => $video->id]);
        VideoMetric::factory()->create(['publication_id' => $publication->id]);

        LlmUsageLog::factory()->create(['content_project_id' => $video->content_project_id]);
    }
}
