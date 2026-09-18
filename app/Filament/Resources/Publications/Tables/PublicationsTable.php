<?php

namespace App\Filament\Resources\Publications\Tables;

use App\Jobs\GenerateCaptionsJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PublicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_at')
            ->columns([
                TextColumn::make('video.title')
                    ->searchable(),
                TextColumn::make('socialAccount.username')
                    ->label('Social account')
                    ->searchable(),
                TextColumn::make('socialAccount.platform')
                    ->badge(),
                TextColumn::make('scheduled_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('external_post_id')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
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
                SelectFilter::make('status')
                    ->options(PublicationStatus::class),
                Filter::make('scheduled_at')
                    ->schema([
                        DatePicker::make('scheduled_from'),
                        DatePicker::make('scheduled_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['scheduled_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('scheduled_at', '>=', $date))
                            ->when($data['scheduled_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('scheduled_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                Action::make('generateCaptions')
                    ->label('Generate Captions')
                    ->visible(fn (Publication $record): bool => in_array($record->status, [PublicationStatus::Draft, PublicationStatus::Scheduled], true)
                        && $record->caption === null)
                    ->requiresConfirmation()
                    ->action(function (Publication $record): void {
                        GenerateCaptionsJob::dispatch($record->id);

                        Notification::make()->title('Caption generation queued')->success()->send();
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
