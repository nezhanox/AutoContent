<?php

namespace App\Filament\Resources\ContentIdeas\Tables;

use App\Jobs\GenerateScriptJob;
use App\Models\ContentIdea;
use App\Models\Enums\ContentIdeaStatus;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContentIdeasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contentProject.name')
                    ->searchable(),
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('topic')
                    ->searchable(),
                TextColumn::make('source')
                    ->searchable(),
                TextColumn::make('source_url')
                    ->searchable(),
                TextColumn::make('score')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('script_status')
                    ->label('Script Status')
                    ->badge()
                    ->state(fn (ContentIdea $record): ?string => $record->scripts()->latest()->value('status')?->value)
                    ->toggleable(),
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
                Action::make('approve')
                    ->visible(fn (ContentIdea $record): bool => $record->status === ContentIdeaStatus::New)
                    ->requiresConfirmation()
                    ->action(function (ContentIdea $record): void {
                        $record->update(['status' => ContentIdeaStatus::Approved]);

                        Notification::make()->title('Idea approved')->success()->send();
                    }),
                Action::make('reject')
                    ->visible(fn (ContentIdea $record): bool => $record->status === ContentIdeaStatus::New)
                    ->requiresConfirmation()
                    ->color('danger')
                    ->action(function (ContentIdea $record): void {
                        $record->update(['status' => ContentIdeaStatus::Rejected]);

                        Notification::make()->title('Idea rejected')->success()->send();
                    }),
                Action::make('generateScript')
                    ->label('Generate Script')
                    ->visible(fn (ContentIdea $record): bool => $record->status === ContentIdeaStatus::Approved)
                    ->requiresConfirmation()
                    ->action(function (ContentIdea $record): void {
                        GenerateScriptJob::dispatch($record->id);

                        Notification::make()->title('Script generation queued')->success()->send();
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
