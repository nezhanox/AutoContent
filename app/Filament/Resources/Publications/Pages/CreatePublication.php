<?php

namespace App\Filament\Resources\Publications\Pages;

use App\Filament\Resources\Publications\Concerns\AppliesScheduledStatus;
use App\Filament\Resources\Publications\PublicationResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePublication extends CreateRecord
{
    use AppliesScheduledStatus;

    protected static string $resource = PublicationResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->applyScheduledStatus($data, currentStatus: null);
    }
}
