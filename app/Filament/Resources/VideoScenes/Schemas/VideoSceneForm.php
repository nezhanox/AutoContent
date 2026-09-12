<?php

namespace App\Filament\Resources\VideoScenes\Schemas;

use App\Models\Enums\VideoSceneType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class VideoSceneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('video_id')
                    ->relationship('video', 'title')
                    ->required(),
                TextInput::make('order')
                    ->required()
                    ->numeric(),
                Select::make('type')
                    ->options(VideoSceneType::class)
                    ->required(),
                TextInput::make('duration')
                    ->required()
                    ->numeric(),
                Textarea::make('text')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('visual_query'),
                Select::make('asset_id')
                    ->relationship('asset', 'id'),
                TextInput::make('start_time')
                    ->numeric(),
                TextInput::make('end_time')
                    ->numeric(),
                TextInput::make('metadata')
                    ->required()
                    ->default('{}')
                    ->disabled(),
            ]);
    }
}
