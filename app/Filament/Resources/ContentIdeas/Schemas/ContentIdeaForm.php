<?php

namespace App\Filament\Resources\ContentIdeas\Schemas;

use App\Models\Enums\ContentIdeaStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ContentIdeaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('content_project_id')
                    ->relationship('contentProject', 'name')
                    ->required(),
                TextInput::make('title')
                    ->required(),
                TextInput::make('topic')
                    ->required(),
                TextInput::make('source')
                    ->required(),
                TextInput::make('source_url')
                    ->url(),
                TextInput::make('source_data'),
                TextInput::make('score')
                    ->numeric(),
                Select::make('status')
                    ->options(ContentIdeaStatus::class)
                    ->required(),
            ]);
    }
}
