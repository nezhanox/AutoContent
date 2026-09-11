<?php

namespace App\Filament\Resources\LlmUsageLogs\Pages;

use App\Filament\Resources\LlmUsageLogs\LlmUsageLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLlmUsageLogs extends ListRecords
{
    protected static string $resource = LlmUsageLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
