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
                    ->selectRaw('ROW_NUMBER() OVER (ORDER BY SUM(latest_metrics.views) DESC) as id, content_ideas.topic, SUM(latest_metrics.views) as total_views')
                    ->groupBy('content_ideas.topic')
                    ->orderByDesc('total_views')
                    ->limit(5)
            )
            ->columns([
                TextColumn::make('topic'),
                TextColumn::make('total_views')->numeric(),
            ])
            ->paginated(false)
            ->defaultKeySort(false);
    }
}
