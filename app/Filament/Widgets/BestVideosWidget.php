<?php

namespace App\Filament\Widgets;

use App\Models\Publication;
use App\Models\VideoMetric;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class BestVideosWidget extends TableWidget
{
    protected static ?string $heading = 'Best Videos';

    public function table(Table $table): Table
    {
        $latestMetrics = VideoMetric::latestPerPublication();

        return $table
            ->query(
                Publication::query()
                    ->joinSub($latestMetrics, 'latest_metrics', 'latest_metrics.publication_id', '=', 'publications.id')
                    ->join('videos', 'videos.id', '=', 'publications.video_id')
                    ->join('social_accounts', 'social_accounts.id', '=', 'publications.social_account_id')
                    ->orderByDesc('latest_metrics.views')
                    ->limit(5)
                    ->select([
                        'publications.id',
                        'videos.title as video_title',
                        'social_accounts.platform',
                        'latest_metrics.views',
                        'latest_metrics.likes',
                        'latest_metrics.comments',
                    ])
            )
            ->columns([
                TextColumn::make('video_title')->label('Video'),
                TextColumn::make('platform')->badge(),
                TextColumn::make('views')->numeric(),
                TextColumn::make('likes')->numeric(),
                TextColumn::make('comments')->numeric(),
            ])
            ->paginated(false);
    }
}
