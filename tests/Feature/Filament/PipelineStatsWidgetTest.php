<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\PipelineStatsWidget;
use App\Models\Enums\PublicationStatus;
use App\Models\Enums\VideoStatus;
use App\Models\Publication;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoMetric;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PipelineStatsWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_pipeline_and_engagement_totals(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Two videos rendered "today", one backdated 2 days — proves the
        // "Videos generated today" stat actually filters by date instead of
        // counting every Rendered video.
        Video::factory()->create(['status' => VideoStatus::Rendered]);
        $renderedToday = Video::factory()->create(['status' => VideoStatus::Rendered]);
        $renderedOld = Video::factory()->create(['status' => VideoStatus::Rendered]);
        Video::whereKey($renderedOld->id)->update(['updated_at' => now()->subDays(2)]);

        // 3 bare Published publications + 1 with a VideoMetric = 4 published total.
        Publication::factory()->count(3)->create(['status' => PublicationStatus::Published]);
        $metricPublication = Publication::factory()->create(['status' => PublicationStatus::Published]);
        VideoMetric::factory()->create([
            'publication_id' => $metricPublication->id,
            'views' => 55555,
            'likes' => 6666,
            'comments' => 77,
            'measured_at' => now(),
        ]);

        // 4 failure notifications sent "now", one of them backdated 2 days —
        // proves "Failed jobs today" filters by date too (expected count: 3).
        for ($i = 0; $i < 4; $i++) {
            Notification::send($user, new PipelineJobFailedNotification('boom', ['attempt' => $i]));
        }
        $backdated = $user->notifications()->latest()->first();
        $backdated->forceFill(['created_at' => now()->subDays(2)])->save();

        // Every asserted number below uses a distinct repeated digit (2, 4, 3, 5, 6, 7)
        // so a wrong stat can't accidentally satisfy another stat's assertion.
        Livewire::test(PipelineStatsWidget::class)
            ->assertSee('2') // Videos generated today
            ->assertSee('4') // Videos published (cumulative)
            ->assertSee('3') // Failed jobs today
            ->assertSee('55555') // Views
            ->assertSee('6666') // Likes
            ->assertSee('77'); // Comments
    }
}
