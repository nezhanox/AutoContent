<?php

namespace App\Filament\Resources\SourceChannels;

use App\Filament\Resources\SourceChannels\Pages\CreateSourceChannel;
use App\Filament\Resources\SourceChannels\Pages\EditSourceChannel;
use App\Filament\Resources\SourceChannels\Pages\ListSourceChannels;
use App\Filament\Resources\SourceChannels\Schemas\SourceChannelForm;
use App\Filament\Resources\SourceChannels\Tables\SourceChannelsTable;
use App\Models\SourceChannel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SourceChannelResource extends Resource
{
    protected static ?string $model = SourceChannel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFilm;

    protected static string|UnitEnum|null $navigationGroup = 'Sources';

    public static function form(Schema $schema): Schema
    {
        return SourceChannelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SourceChannelsTable::configure($table);
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
            'index' => ListSourceChannels::route('/'),
            'create' => CreateSourceChannel::route('/create'),
            'edit' => EditSourceChannel::route('/{record}/edit'),
        ];
    }
}
