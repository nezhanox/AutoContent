<?php

namespace App\Filament\Resources\ContentIdeas\Pages;

use App\Filament\Resources\ContentIdeas\ContentIdeaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContentIdeas extends ListRecords
{
    protected static string $resource = ContentIdeaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
