<?php

namespace App\Filament\Resources\VideoMetrics\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class VideoMetricForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('publication_id')
                    ->relationship('publication', 'id')
                    ->required(),
                TextInput::make('views')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('likes')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('comments')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('shares')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('saves')
                    ->numeric(),
                TextInput::make('watch_time')
                    ->numeric(),
                TextInput::make('completion_rate')
                    ->numeric(),
                TextInput::make('followers_gained')
                    ->numeric(),
                TextInput::make('metadata')
                    ->required()
                    ->default('{}')
                    ->disabled(),
                DateTimePicker::make('measured_at')
                    ->required(),
            ]);
    }
}
