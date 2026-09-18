<?php

namespace Tests\Feature\Filament;

use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use App\Models\VideoMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoMetricResourceViewOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_create_route_no_longer_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/video-metrics/create')->assertNotFound();
    }

    public function test_the_edit_route_no_longer_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);
        $metric = VideoMetric::factory()->create(['publication_id' => $publication->id]);

        $this->get("/admin/video-metrics/{$metric->id}/edit")->assertNotFound();
    }

    public function test_the_list_page_shows_metrics(): void
    {
        $this->actingAs(User::factory()->create());

        $publication = Publication::factory()->create(['status' => PublicationStatus::Published]);
        // Under 1000: the views column's ->numeric() formatting inserts a thousands separator above that (e.g. "4,242"), which would break a literal assertSee.
        VideoMetric::factory()->create(['publication_id' => $publication->id, 'views' => 999]);

        $this->get('/admin/video-metrics')->assertSuccessful()->assertSee('999');
    }
}
