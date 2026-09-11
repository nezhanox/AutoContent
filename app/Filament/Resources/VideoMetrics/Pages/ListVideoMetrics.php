<?php

namespace App\Filament\Resources\VideoMetrics\Pages;

use App\Filament\Resources\VideoMetrics\VideoMetricResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVideoMetrics extends ListRecords
{
    protected static string $resource = VideoMetricResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
