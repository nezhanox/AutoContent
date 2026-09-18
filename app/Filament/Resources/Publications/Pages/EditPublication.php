<?php

namespace App\Filament\Resources\Publications\Pages;

use App\Filament\Resources\Publications\Concerns\AppliesScheduledStatus;
use App\Filament\Resources\Publications\PublicationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPublication extends EditRecord
{
    use AppliesScheduledStatus;

    protected static string $resource = PublicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->applyScheduledStatus($data, currentStatus: $this->record->status);
    }
}
