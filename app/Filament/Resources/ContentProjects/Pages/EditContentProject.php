<?php

namespace App\Filament\Resources\ContentProjects\Pages;

use App\Filament\Resources\ContentProjects\ContentProjectResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditContentProject extends EditRecord
{
    protected static string $resource = ContentProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['settings'] = array_replace_recursive($this->record->settings ?? [], $data['settings'] ?? []);

        return $data;
    }
}
