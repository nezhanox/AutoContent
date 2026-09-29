<?php

namespace App\Filament\Resources\SourceChannels\Tables;

use App\Models\Enums\SourceChannelMode;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SourceChannelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('url')
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('mode')
                    ->badge()
                    ->formatStateUsing(fn (SourceChannelMode|string|null $state): ?string => $state instanceof SourceChannelMode ? $state->value : $state),
                TextColumn::make('contentProject.name')
                    ->label('Project'),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('last_checked_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('source_videos_count')
                    ->label('Videos')
                    ->counts('sourceVideos'),
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
