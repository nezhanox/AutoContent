<?php

namespace App\Filament\Resources\VideoScenes;

use App\Filament\Resources\VideoScenes\Pages\CreateVideoScene;
use App\Filament\Resources\VideoScenes\Pages\EditVideoScene;
use App\Filament\Resources\VideoScenes\Pages\ListVideoScenes;
use App\Filament\Resources\VideoScenes\Schemas\VideoSceneForm;
use App\Filament\Resources\VideoScenes\Tables\VideoScenesTable;
use App\Models\VideoScene;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class VideoSceneResource extends Resource
{
    protected static ?string $model = VideoScene::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Content Projects';

    public static function form(Schema $schema): Schema
    {
        return VideoSceneForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VideoScenesTable::configure($table);
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
            'index' => ListVideoScenes::route('/'),
            'create' => CreateVideoScene::route('/create'),
            'edit' => EditVideoScene::route('/{record}/edit'),
        ];
    }
}
