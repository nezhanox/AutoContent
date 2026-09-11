<?php

namespace App\Filament\Resources\Scripts;

use App\Filament\Resources\Scripts\Pages\CreateScript;
use App\Filament\Resources\Scripts\Pages\EditScript;
use App\Filament\Resources\Scripts\Pages\ListScripts;
use App\Filament\Resources\Scripts\Schemas\ScriptForm;
use App\Filament\Resources\Scripts\Tables\ScriptsTable;
use App\Models\Script;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ScriptResource extends Resource
{
    protected static ?string $model = Script::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Content Projects';

    public static function form(Schema $schema): Schema
    {
        return ScriptForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScriptsTable::configure($table);
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
            'index' => ListScripts::route('/'),
            'create' => CreateScript::route('/create'),
            'edit' => EditScript::route('/{record}/edit'),
        ];
    }
}
