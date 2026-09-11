<?php

namespace App\Filament\Resources\VideoMetrics;

use App\Filament\Resources\VideoMetrics\Pages\CreateVideoMetric;
use App\Filament\Resources\VideoMetrics\Pages\EditVideoMetric;
use App\Filament\Resources\VideoMetrics\Pages\ListVideoMetrics;
use App\Filament\Resources\VideoMetrics\Schemas\VideoMetricForm;
use App\Filament\Resources\VideoMetrics\Tables\VideoMetricsTable;
use App\Models\VideoMetric;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class VideoMetricResource extends Resource
{
    protected static ?string $model = VideoMetric::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    public static function form(Schema $schema): Schema
    {
        return VideoMetricForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VideoMetricsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVideoMetrics::route('/'),
            'create' => CreateVideoMetric::route('/create'),
            'edit' => EditVideoMetric::route('/{record}/edit'),
        ];
    }
}
