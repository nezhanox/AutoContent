<?php

namespace App\Filament\Resources\Scripts;

use App\Filament\Resources\Scripts\Pages\ListScripts;
use App\Filament\Resources\Scripts\Pages\ViewScript;
use App\Filament\Resources\Scripts\Schemas\ScriptForm;
use App\Filament\Resources\Scripts\Tables\ScriptsTable;
use App\Models\Script;
use BackedEnum;
use Closure;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * The only remaining record route is `view` (`/{record}`), so any
     * non-numeric segment — e.g. the old, now-removed `/create` path — can
     * never match a real record. Short-circuit those before they reach the
     * database: the `id` column is a Postgres bigint, and comparing it
     * against a non-numeric string throws a SQL error instead of yielding
     * the clean "no match" that a 404 needs.
     */
    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        if (! is_numeric($key)) {
            return null;
        }

        return parent::resolveRecordRouteBinding($key, $modifyQuery);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScripts::route('/'),
            'view' => ViewScript::route('/{record}'),
        ];
    }
}
