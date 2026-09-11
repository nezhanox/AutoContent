<?php

namespace App\Filament\Resources\ContentIdeas;

use App\Filament\Resources\ContentIdeas\Pages\CreateContentIdea;
use App\Filament\Resources\ContentIdeas\Pages\EditContentIdea;
use App\Filament\Resources\ContentIdeas\Pages\ListContentIdeas;
use App\Filament\Resources\ContentIdeas\Schemas\ContentIdeaForm;
use App\Filament\Resources\ContentIdeas\Tables\ContentIdeasTable;
use App\Models\ContentIdea;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ContentIdeaResource extends Resource
{
    protected static ?string $model = ContentIdea::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Content Projects';

    public static function form(Schema $schema): Schema
    {
        return ContentIdeaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContentIdeasTable::configure($table);
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
            'index' => ListContentIdeas::route('/'),
            'create' => CreateContentIdea::route('/create'),
            'edit' => EditContentIdea::route('/{record}/edit'),
        ];
    }
}
