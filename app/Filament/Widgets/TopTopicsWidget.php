<?php

namespace App\Filament\Widgets;

use App\Models\ContentIdea;
use App\Models\VideoMetric;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TopTopicsWidget extends TableWidget
{
    protected static ?string $heading = 'Top Performing Topics';

    public function table(Table $table): Table
    {
        $latestMetrics = VideoMetric::latestPerPublication();

        return $table
            ->query(
                ContentIdea::query()
                    ->join('videos', 'videos.content_idea_id', '=', 'content_ideas.id')
                    ->join('publications', 'publications.video_id', '=', 'videos.id')
                    ->joinSub($latestMetrics, 'latest_metrics', 'latest_metrics.publication_id', '=', 'publications.id')
                    // ContentIdea's `id` is implicitly cast to int (Eloquent auto-adds
                    // getKeyName() => getKeyType() to $casts for incrementing models),
                    // so aliasing a non-numeric column (topic) as `id` collapses every
                    // row's id to 0 via PHP's (int) cast, causing the table to render
                    // only one row. ROW_NUMBER() produces genuinely distinct integers.
                    ->selectRaw('ROW_NUMBER() OVER (ORDER BY SUM(latest_metrics.views) DESC) as id, content_ideas.topic, SUM(latest_metrics.views) as total_views')
                    ->groupBy('content_ideas.topic')
                    ->orderByDesc('total_views')
                    ->limit(5)
            )
            ->columns([
                TextColumn::make('topic'),
                TextColumn::make('total_views')->numeric(),
            ])
            // Filament's default key-sort tiebreak would append
            // `ORDER BY content_ideas.id`, which Postgres rejects here since that
            // column isn't in GROUP BY nor aggregated.
            ->paginated(false)
            ->defaultKeySort(false);
    }
}
