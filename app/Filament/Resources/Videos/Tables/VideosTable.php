<?php

namespace App\Filament\Resources\Videos\Tables;

use App\Jobs\CollectVideoAssetsJob;
use App\Jobs\GenerateSubtitlesJob;
use App\Jobs\GenerateVoiceoverJob;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VideosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contentProject.name')
                    ->searchable(),
                TextColumn::make('contentIdea.title')
                    ->searchable(),
                TextColumn::make('script.id')
                    ->searchable(),
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('duration')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('width')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('height')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('file_path')
                    ->searchable(),
                TextColumn::make('thumbnail_path')
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
                Action::make('generateVoiceover')
                    ->label('Generate Voiceover')
                    ->visible(fn (Video $record): bool => $record->status === VideoStatus::ScriptGenerated
                        && ! $record->voiceover()->exists())
                    ->requiresConfirmation()
                    ->action(function (Video $record): void {
                        GenerateVoiceoverJob::dispatch($record->id);

                        Notification::make()->title('Voiceover generation queued')->success()->send();
                    }),
                Action::make('collectAssets')
                    ->label('Collect Assets')
                    ->visible(fn (Video $record): bool => $record->status === VideoStatus::VoiceGenerated)
                    ->requiresConfirmation()
                    ->action(function (Video $record): void {
                        CollectVideoAssetsJob::dispatch($record->id);

                        Notification::make()->title('Asset collection queued')->success()->send();
                    }),
                Action::make('generateSubtitles')
                    ->label('Generate Subtitles')
                    ->visible(fn (Video $record): bool => $record->status === VideoStatus::AssetsReady
                        && $record->subtitle_id === null)
                    ->requiresConfirmation()
                    ->action(function (Video $record): void {
                        GenerateSubtitlesJob::dispatch($record->id);

                        Notification::make()->title('Subtitle generation queued')->success()->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
