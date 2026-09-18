<?php

namespace App\Filament\Resources\VideoMetrics\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VideoMetricsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('publication.id')
                    ->searchable(),
                TextColumn::make('views')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('likes')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('comments')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('shares')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('saves')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('watch_time')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('completion_rate')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('followers_gained')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('measured_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
