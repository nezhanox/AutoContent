<?php

namespace App\Filament\Resources\Scripts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ScriptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('content_idea_id')
                    ->relationship('contentIdea', 'title')
                    ->required(),
                TextInput::make('provider')
                    ->required(),
                TextInput::make('model')
                    ->required(),
                TextInput::make('prompt_version')
                    ->required(),
                Textarea::make('content')
                    ->columnSpanFull(),
                Textarea::make('hook')
                    ->columnSpanFull(),
                TextInput::make('estimated_duration')
                    ->numeric(),
                TextInput::make('metadata')
                    ->required()
                    ->default('{}')
                    ->disabled(),
                TextInput::make('status')
                    ->required(),
            ]);
    }
}
