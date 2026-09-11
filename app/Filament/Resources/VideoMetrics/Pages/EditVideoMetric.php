<?php

namespace App\Filament\Resources\VideoMetrics\Pages;

use App\Filament\Resources\VideoMetrics\VideoMetricResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVideoMetric extends EditRecord
{
    protected static string $resource = VideoMetricResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
