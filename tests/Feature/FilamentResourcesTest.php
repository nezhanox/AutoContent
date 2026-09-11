<?php

namespace Tests\Feature;

use App\Models\User;
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

        foreach ($this->resourceSlugs as $slug) {
            $response = $this->get("/admin/{$slug}");

            $response->assertSuccessful();
        }
    }
}
