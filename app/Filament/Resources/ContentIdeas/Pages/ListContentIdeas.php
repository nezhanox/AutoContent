<?php

namespace App\Filament\Resources\ContentIdeas\Pages;

use App\Domain\Content\Services\GenerateContentIdeaService;
use App\Filament\Resources\ContentIdeas\ContentIdeaResource;
use App\Models\ContentProject;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListContentIdeas extends ListRecords
{
    protected static string $resource = ContentIdeaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateIdea')
                ->label('Generate Idea')
                ->schema([
                    Select::make('content_project_id')
                        ->label('Content Project')
                        ->options(fn (): array => ContentProject::query()->pluck('name', 'id')->all())
                        ->required(),
                    TextInput::make('topic')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(GenerateContentIdeaService::class)->generate(
                            ContentProject::findOrFail($data['content_project_id']),
                            $data['topic'],
                        );

                        Notification::make()->title('Idea generated')->success()->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Idea generation failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            CreateAction::make(),
        ];
    }
}
