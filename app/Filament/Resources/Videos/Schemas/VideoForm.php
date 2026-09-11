<?php

namespace App\Filament\Resources\Videos\Schemas;

use App\Models\Enums\VideoStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class VideoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('content_project_id')
                    ->relationship('contentProject', 'name')
                    ->required(),
                Select::make('content_idea_id')
                    ->relationship('contentIdea', 'title')
                    ->required(),
                Select::make('script_id')
                    ->relationship('script', 'id')
                    ->required(),
                TextInput::make('title')
                    ->required(),
                Textarea::make('description')
                    ->required()
                    ->columnSpanFull(),
                Select::make('status')
                    ->options(VideoStatus::class)
                    ->required(),
                TextInput::make('duration')
                    ->numeric(),
                TextInput::make('width')
                    ->numeric(),
                TextInput::make('height')
                    ->numeric(),
                TextInput::make('file_path'),
                TextInput::make('thumbnail_path'),
                TextInput::make('metadata')
                    ->required()
                    ->default('{}'),
                Textarea::make('error_message')
                    ->columnSpanFull(),
            ]);
    }
}
