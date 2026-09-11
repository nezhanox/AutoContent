<?php

namespace App\Filament\Resources\Voiceovers;

use App\Filament\Resources\Voiceovers\Pages\CreateVoiceover;
use App\Filament\Resources\Voiceovers\Pages\EditVoiceover;
use App\Filament\Resources\Voiceovers\Pages\ListVoiceovers;
use App\Filament\Resources\Voiceovers\Schemas\VoiceoverForm;
use App\Filament\Resources\Voiceovers\Tables\VoiceoversTable;
use App\Models\Voiceover;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class VoiceoverResource extends Resource
{
    protected static ?string $model = Voiceover::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Media';

    public static function form(Schema $schema): Schema
    {
        return VoiceoverForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VoiceoversTable::configure($table);
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
            'index' => ListVoiceovers::route('/'),
            'create' => CreateVoiceover::route('/create'),
            'edit' => EditVoiceover::route('/{record}/edit'),
        ];
    }
}
