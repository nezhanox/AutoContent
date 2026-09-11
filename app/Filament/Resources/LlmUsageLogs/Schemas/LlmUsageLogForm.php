<?php

namespace App\Filament\Resources\LlmUsageLogs\Schemas;

use App\Models\Enums\LlmUsageLogStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LlmUsageLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('content_project_id')
                    ->relationship('contentProject', 'name'),
                TextInput::make('purpose')
                    ->required(),
                TextInput::make('provider')
                    ->required(),
                TextInput::make('model')
                    ->required(),
                TextInput::make('prompt_tokens')
                    ->required()
                    ->numeric(),
                TextInput::make('completion_tokens')
                    ->required()
                    ->numeric(),
                TextInput::make('cost')
                    ->numeric()
                    ->prefix('$'),
                TextInput::make('duration_ms')
                    ->required()
                    ->numeric(),
                Select::make('status')
                    ->options(LlmUsageLogStatus::class)
                    ->required(),
                Textarea::make('error_message')
                    ->columnSpanFull(),
                TextInput::make('metadata')
                    ->required()
                    ->default('{}'),
            ]);
    }
}
