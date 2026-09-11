<?php

namespace App\Filament\Resources\LlmUsageLogs\Pages;

use App\Filament\Resources\LlmUsageLogs\LlmUsageLogResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLlmUsageLog extends EditRecord
{
    protected static string $resource = LlmUsageLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
