<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\BestVideosWidget;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BestVideosWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ranks_publications_by_latest_views_descending(): void
    {
        $this->actingAs(User::factory()->create());

        $topVideo = Video::factory()->create(['title' => 'Top Video']);
        $topPublication = Publication::factory()->create([
            'video_id' => $topVideo->id,
            'status' => PublicationStatus::Published,
        ]);
        VideoMetric::factory()->create([
            'publication_id' => $topPublication->id,
            'views' => 9000,
            'measured_at' => now(),
        ]);

        $lowVideo = Video::factory()->create(['title' => 'Low Video']);
        $lowPublication = Publication::factory()->create([
            'video_id' => $lowVideo->id,
            'status' => PublicationStatus::Published,
        ]);
        VideoMetric::factory()->create([
            'publication_id' => $lowPublication->id,
            'views' => 10,
            'measured_at' => now(),
        ]);

        Livewire::test(BestVideosWidget::class)
            ->assertSeeHtmlInOrder(['Top Video', 'Low Video']);
    }
}
