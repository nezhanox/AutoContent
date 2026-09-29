<?php

namespace App\Filament\Resources\SourceChannels\RelationManagers;

use App\Jobs\CreateClipVideosJob;
use App\Jobs\DownloadSourceVideoJob;
use App\Jobs\SelectClipsJob;
use App\Jobs\TranscribeSourceVideoJob;
use App\Models\Enums\SourceVideoStatus;
use App\Models\SourceVideo;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SourceVideosRelationManager extends RelationManager
{
    protected static string $relationship = 'sourceVideos';

    protected static ?string $title = 'Source videos';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->limit(50)
                    ->tooltip(fn (SourceVideo $record): string => $record->title)
                    ->searchable(),
                TextColumn::make('youtube_id')
                    ->label('YouTube ID')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SourceVideoStatus|string|null $state): ?string => $state instanceof SourceVideoStatus ? $state->value : $state)
                    ->color(fn (SourceVideoStatus|string|null $state): string => self::statusColor($state)),
                TextColumn::make('failed_stage')
                    ->placeholder('-'),
                TextColumn::make('error_message')
                    ->limit(60)
                    ->tooltip(fn (SourceVideo $record): ?string => $record->error_message)
                    ->placeholder('-'),
                TextColumn::make('duration')
                    ->formatStateUsing(fn (mixed $state): string => self::formatDuration((float) $state))
                    ->sortable(),
                TextColumn::make('clips_count')
                    ->label('Clips')
                    ->counts('clips'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(SourceVideoStatus::cases())->mapWithKeys(
                        fn (SourceVideoStatus $status): array => [$status->value => $status->value]
                    )->all()),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry')
                    ->color('warning')
                    ->visible(fn (SourceVideo $record): bool => $record->status === SourceVideoStatus::Failed)
                    ->requiresConfirmation()
                    ->action(function (SourceVideo $record): void {
                        $stage = $record->failed_stage;

                        $status = match ($stage) {
                            'download' => SourceVideoStatus::Discovered,
                            'transcribe' => SourceVideoStatus::Downloaded,
                            'select' => SourceVideoStatus::Transcribed,
                            'create' => SourceVideoStatus::ClipsSelected,
                            default => null,
                        };

                        if ($status === null) {
                            Notification::make()->title('Cannot retry - unknown failed stage')->danger()->send();

                            return;
                        }

                        $record->update([
                            'status' => $status,
                            'failed_stage' => null,
                            'error_message' => null,
                        ]);

                        match ($stage) {
                            'download' => DownloadSourceVideoJob::dispatch($record->id),
                            'transcribe' => TranscribeSourceVideoJob::dispatch($record->id),
                            'select' => SelectClipsJob::dispatch($record->id),
                            'create' => CreateClipVideosJob::dispatch($record->id),
                        };

                        Notification::make()->title('Retry queued')->success()->send();
                    }),
            ]);
    }

    private static function statusColor(SourceVideoStatus|string|null $state): string
    {
        $status = $state instanceof SourceVideoStatus ? $state : SourceVideoStatus::tryFrom((string) $state);

        return match ($status) {
            SourceVideoStatus::Failed => 'danger',
            SourceVideoStatus::ClipsCreated => 'success',
            SourceVideoStatus::Skipped, SourceVideoStatus::NoClips => 'gray',
            SourceVideoStatus::Discovered => 'info',
            default => 'warning',
        };
    }

    private static function formatDuration(float $seconds): string
    {
        $total = (int) round($seconds);

        return sprintf('%d:%02d', intdiv($total, 60), $total % 60);
    }
}
