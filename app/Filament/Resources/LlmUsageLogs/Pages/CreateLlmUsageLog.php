<?php

namespace App\Filament\Resources\LlmUsageLogs\Pages;

use App\Filament\Resources\LlmUsageLogs\LlmUsageLogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLlmUsageLog extends CreateRecord
{
    protected static string $resource = LlmUsageLogResource::class;
}
