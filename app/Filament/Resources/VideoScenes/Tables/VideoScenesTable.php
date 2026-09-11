<?php

namespace App\Filament\Resources\VideoScenes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VideoScenesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('video.title')
                    ->searchable(),
                TextColumn::make('order')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('duration')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('visual_query')
                    ->searchable(),
                TextColumn::make('asset.id')
                    ->searchable(),
                TextColumn::make('start_time')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('end_time')
                    ->numeric()
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
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
