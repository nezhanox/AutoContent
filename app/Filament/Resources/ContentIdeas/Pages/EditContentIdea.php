<?php

namespace App\Filament\Resources\ContentIdeas\Pages;

use App\Filament\Resources\ContentIdeas\ContentIdeaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditContentIdea extends EditRecord
{
    protected static string $resource = ContentIdeaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
