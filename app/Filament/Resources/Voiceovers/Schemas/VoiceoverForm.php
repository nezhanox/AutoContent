<?php

namespace App\Filament\Resources\Voiceovers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class VoiceoverForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('video_id')
                    ->relationship('video', 'title')
                    ->required(),
                TextInput::make('provider')
                    ->required(),
                TextInput::make('voice')
                    ->required(),
                Textarea::make('text')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('file_path'),
                TextInput::make('duration')
                    ->numeric(),
                TextInput::make('metadata')
                    ->required()
                    ->default('{}'),
                TextInput::make('status')
                    ->required(),
            ]);
    }
}
