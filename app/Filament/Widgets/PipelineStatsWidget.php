<?php

namespace App\Filament\Widgets;

use App\Models\Enums\PublicationStatus;
use App\Models\Enums\VideoStatus;
use App\Models\Publication;
use App\Models\Video;
use App\Models\VideoMetric;
use App\Notifications\PipelineJobFailedNotification;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PipelineStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $latestMetrics = VideoMetric::latestPerPublication()->get();

        return [
            Stat::make('Videos generated today', Video::where('status', VideoStatus::Rendered)
                ->whereDate('updated_at', today())
                ->count()),
            Stat::make('Videos published', Publication::where('status', PublicationStatus::Published)->count()),
            // NotifiesOnPermanentFailure sends one notification row per admin
            // user (Notification::send(User::all(), ...)) — counting the
            // `notifications` table directly multiplies every failure by the
            // admin count. Counting the current viewer's own notifications
            // gives exactly one row per failure regardless of admin count.
            Stat::make('Failed jobs today', Filament::auth()->user()
                ->notifications()
                ->where('type', PipelineJobFailedNotification::class)
                ->whereDate('created_at', today())
                ->count()),
            Stat::make('Views', (string) $latestMetrics->sum('views')),
            Stat::make('Likes', (string) $latestMetrics->sum('likes')),
            Stat::make('Comments', (string) $latestMetrics->sum('comments')),
        ];
    }
}
