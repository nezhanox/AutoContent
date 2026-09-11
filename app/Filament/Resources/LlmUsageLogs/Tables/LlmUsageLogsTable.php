<?php

namespace App\Filament\Resources\LlmUsageLogs\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LlmUsageLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contentProject.name')
                    ->searchable(),
                TextColumn::make('purpose')
                    ->searchable(),
                TextColumn::make('provider')
                    ->searchable(),
                TextColumn::make('model')
                    ->searchable(),
                TextColumn::make('prompt_tokens')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('completion_tokens')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('cost')
                    ->money()
                    ->sortable(),
                TextColumn::make('duration_ms')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('created_at')
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
