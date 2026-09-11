<?php

namespace App\Filament\Resources\VideoScenes\Pages;

use App\Filament\Resources\VideoScenes\VideoSceneResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVideoScenes extends ListRecords
{
    protected static string $resource = VideoSceneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
