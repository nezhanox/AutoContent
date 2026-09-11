<?php

namespace App\Filament\Resources\LlmUsageLogs;

use App\Filament\Resources\LlmUsageLogs\Pages\CreateLlmUsageLog;
use App\Filament\Resources\LlmUsageLogs\Pages\EditLlmUsageLog;
use App\Filament\Resources\LlmUsageLogs\Pages\ListLlmUsageLogs;
use App\Filament\Resources\LlmUsageLogs\Schemas\LlmUsageLogForm;
use App\Filament\Resources\LlmUsageLogs\Tables\LlmUsageLogsTable;
use App\Models\LlmUsageLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class LlmUsageLogResource extends Resource
{
    protected static ?string $model = LlmUsageLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    public static function form(Schema $schema): Schema
    {
        return LlmUsageLogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LlmUsageLogsTable::configure($table);
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
            'index' => ListLlmUsageLogs::route('/'),
            'create' => CreateLlmUsageLog::route('/create'),
            'edit' => EditLlmUsageLog::route('/{record}/edit'),
        ];
    }
}
