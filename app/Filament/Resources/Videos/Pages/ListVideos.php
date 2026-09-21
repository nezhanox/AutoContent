<?php

namespace App\Filament\Resources\Videos\Pages;

use App\Domain\Content\Services\GenerateContentIdeaService;
use App\Filament\Resources\Videos\VideoResource;
use App\Jobs\GenerateScriptJob;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListVideos extends ListRecords
{
    protected static string $resource = VideoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateVideo')
                ->label('Generate Video')
                ->schema([
                    Select::make('content_project_id')
                        ->label('Channel')
                        ->options(fn (): array => ContentProject::query()->pluck('name', 'id')->all())
                        ->required(),
                    TextInput::make('topic')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        $idea = app(GenerateContentIdeaService::class)->generate(
                            ContentProject::findOrFail($data['content_project_id']),
                            $data['topic'],
                        );

                        $idea->update(['status' => ContentIdeaStatus::Approved]);

                        GenerateScriptJob::dispatch($idea->id);

                        Notification::make()->title('Generation started')->success()->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Generation failed to start')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            CreateAction::make(),
        ];
    }
}
