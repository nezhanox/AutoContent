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
        VideoMetric::factory()->create(['publication_id' => $publication->id, 'views' => 4242]);

        $this->get('/admin/video-metrics')->assertSuccessful()->assertSee('4242');
    }
}
