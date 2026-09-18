<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\TopTopicsWidget;
use App\Models\ContentIdea;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TopTopicsWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ranks_topics_by_summed_latest_views_descending(): void
    {
        $this->actingAs(User::factory()->create());

        // Created in reverse of the expected sort order — Gardening Tips first —
        // so a query that dropped its `orderByDesc` and fell back to insertion/
        // scan order would produce ['Gardening Tips', 'AI News'] and fail this
        // assertion, instead of accidentally passing (see Task 7's fix-loop
        // finding: an earlier "hot first, cold second" fixture couldn't
        // distinguish a real sort from incidental insertion order).
        $coldIdea = ContentIdea::factory()->create(['topic' => 'Gardening Tips']);
        $coldVideo = Video::factory()->create(['content_idea_id' => $coldIdea->id]);
        $coldPublication = Publication::factory()->create([
            'video_id' => $coldVideo->id,
            'status' => PublicationStatus::Published,
        ]);
        VideoMetric::factory()->create([
            'publication_id' => $coldPublication->id,
            'views' => 20,
            'measured_at' => now(),
        ]);

        $hotIdea = ContentIdea::factory()->create(['topic' => 'AI News']);
        $hotVideo = Video::factory()->create(['content_idea_id' => $hotIdea->id]);
        $hotPublication = Publication::factory()->create([
            'video_id' => $hotVideo->id,
            'status' => PublicationStatus::Published,
        ]);
        VideoMetric::factory()->create([
            'publication_id' => $hotPublication->id,
            'views' => 5000,
            'measured_at' => now(),
        ]);

        Livewire::test(TopTopicsWidget::class)
            ->assertSeeHtmlInOrder(['AI News', 'Gardening Tips']);
    }
}
