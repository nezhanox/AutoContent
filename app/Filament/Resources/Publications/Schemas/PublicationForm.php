<?php

namespace App\Filament\Resources\Publications\Schemas;

use App\Models\Enums\PublicationStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PublicationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('video_id')
                    ->relationship('video', 'title')
                    ->required(),
                Select::make('social_account_id')
                    ->relationship('socialAccount', 'id')
                    ->required(),
                DateTimePicker::make('scheduled_at'),
                DateTimePicker::make('published_at'),
                TextInput::make('external_post_id'),
                Select::make('status')
                    ->options(PublicationStatus::class)
                    ->required(),
                Textarea::make('error_message')
                    ->columnSpanFull(),
                TextInput::make('metadata')
                    ->required()
                    ->default('{}')
                    ->disabled(),
            ]);
    }
}
