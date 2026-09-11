<?php

namespace App\Filament\Resources\VideoScenes\Pages;

use App\Filament\Resources\VideoScenes\VideoSceneResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVideoScene extends EditRecord
{
    protected static string $resource = VideoSceneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
