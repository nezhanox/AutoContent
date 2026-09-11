<?php

namespace App\Filament\Resources\Voiceovers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VoiceoversTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('video.title')
                    ->searchable(),
                TextColumn::make('provider')
                    ->searchable(),
                TextColumn::make('voice')
                    ->searchable(),
                TextColumn::make('file_path')
                    ->searchable(),
                TextColumn::make('duration')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->searchable(),
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
