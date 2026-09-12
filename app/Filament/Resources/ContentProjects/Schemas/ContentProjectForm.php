<?php

namespace App\Filament\Resources\ContentProjects\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ContentProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('niche')
                    ->required(),
                TextInput::make('language')
                    ->required(),
                TextInput::make('target_platforms')
                    ->required()
                    ->default('[]')
                    ->disabled(),
                TextInput::make('status')
                    ->required(),
                TextInput::make('settings')
                    ->required()
                    ->default('{}')
                    ->disabled(),
            ]);
    }
}
