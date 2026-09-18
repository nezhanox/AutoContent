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
        // Two admin users: NotifiesOnPermanentFailure sends one notification
        // row PER admin (Notification::send(User::all(), ...)), so a widget
        // that (incorrectly) counted every `notifications` row instead of the
        // current viewer's own would report double the real failure count —
        // a single-user fixture can't expose that bug.
        $viewer = User::factory()->create();
        $otherAdmin = User::factory()->create();
        $this->actingAs($viewer);

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

        // 4 failure notifications sent "now" to both admins, one occurrence
        // backdated 2 days for both — proves "Failed jobs today" filters by
        // date (expected per-viewer count: 3), independent of admin count.
        for ($i = 0; $i < 4; $i++) {
            Notification::send([$viewer, $otherAdmin], new PipelineJobFailedNotification('boom', ['attempt' => $i]));
        }
        $viewer->notifications()->latest()->first()->forceFill(['created_at' => now()->subDays(2)])->save();
        $otherAdmin->notifications()->latest()->first()->forceFill(['created_at' => now()->subDays(2)])->save();

        // Assert the first three stats' raw values directly rather than via
        // assertSee: a single/double-digit number appears incidentally all
        // over a rendered Livewire payload (wire:snapshot JSON, checksums,
        // Tailwind classes), so assertSee('2')-style checks pass regardless
        // of whether the underlying query is even correct.
        $stats = (function (): array {
            return $this->getStats();
        })->call(new PipelineStatsWidget);

        $this->assertSame(2, $stats[0]->getValue()); // Videos generated today
        $this->assertSame(4, $stats[1]->getValue()); // Videos published (cumulative)
        $this->assertSame(3, $stats[2]->getValue()); // Failed jobs today

        // Views/Likes/Comments are 5/4/2-digit sums with no plausible
        // incidental collision in the rendered markup — assertSee is safe here.
        Livewire::test(PipelineStatsWidget::class)
            ->assertSee('55555') // Views
            ->assertSee('6666') // Likes
            ->assertSee('77'); // Comments
    }
}
