<?php

namespace App\Filament\Resources\Videos\Tables;

use App\Jobs\CollectVideoAssetsJob;
use App\Jobs\GenerateScenesJob;
use App\Jobs\GenerateSubtitlesJob;
use App\Jobs\GenerateVoiceoverJob;
use App\Jobs\QualityCheckVideoJob;
use App\Jobs\RenderVideoJob;
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
                TextColumn::make('stage')
                    ->label('Stage')
                    ->state(fn (Video $record): string => $record->currentStageLabel())
                    ->badge()
                    ->color(fn (Video $record): string => match (true) {
                        $record->status === VideoStatus::Failed => 'danger',
                        $record->status === VideoStatus::Rendered && $record->quality_report !== null => 'success',
                        default => 'warning',
                    }),
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
                Action::make('renderVideo')
                    ->label('Render Video')
                    ->visible(fn (Video $record): bool => $record->status === VideoStatus::AssetsReady
                        && $record->subtitle_id !== null)
                    ->requiresConfirmation()
                    ->action(function (Video $record): void {
                        RenderVideoJob::dispatch($record->id);

                        Notification::make()->title('Rendering queued')->success()->send();
                    }),
                Action::make('checkQuality')
                    ->label('Check Quality')
                    ->visible(fn (Video $record): bool => $record->status === VideoStatus::Rendered)
                    ->requiresConfirmation()
                    ->action(function (Video $record): void {
                        QualityCheckVideoJob::dispatch($record->id);

                        Notification::make()->title('Quality check queued')->success()->send();
                    }),
                Action::make('retry')
                    ->label('Retry')
                    ->color('warning')
                    ->visible(fn (Video $record): bool => $record->status === VideoStatus::Failed)
                    ->requiresConfirmation()
                    ->action(function (Video $record): void {
                        $stage = $record->failed_stage;

                        $record->update([
                            'status' => match ($stage) {
                                'scenes', 'voiceover' => VideoStatus::ScriptGenerated,
                                'assets' => VideoStatus::VoiceGenerated,
                                'subtitles', 'render' => VideoStatus::AssetsReady,
                                'quality_check' => VideoStatus::Rendered,
                                default => $record->status,
                            },
                            'failed_stage' => null,
                            'error_message' => null,
                        ]);

                        match ($stage) {
                            'scenes' => GenerateScenesJob::dispatch($record->script_id),
                            'voiceover' => GenerateVoiceoverJob::dispatch($record->id),
                            'assets' => CollectVideoAssetsJob::dispatch($record->id),
                            'subtitles' => GenerateSubtitlesJob::dispatch($record->id),
                            'render' => RenderVideoJob::dispatch($record->id),
                            'quality_check' => QualityCheckVideoJob::dispatch($record->id),
                            default => null,
                        };

                        Notification::make()->title('Retry queued')->success()->send();
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
