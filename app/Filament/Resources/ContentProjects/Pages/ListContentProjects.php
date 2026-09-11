<?php

namespace App\Filament\Resources\ContentProjects\Pages;

use App\Filament\Resources\ContentProjects\ContentProjectResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContentProjects extends ListRecords
{
    protected static string $resource = ContentProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
