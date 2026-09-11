<?php

namespace App\Filament\Resources\SocialAccounts\Schemas;

use App\Models\Enums\SocialPlatform;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SocialAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('content_project_id')
                    ->relationship('contentProject', 'name')
                    ->required(),
                Select::make('platform')
                    ->options(SocialPlatform::class)
                    ->required(),
                TextInput::make('external_account_id')
                    ->required(),
                TextInput::make('username')
                    ->required(),
                DateTimePicker::make('token_expires_at'),
                TextInput::make('metadata')
                    ->required()
                    ->default('{}'),
                TextInput::make('status')
                    ->required(),
            ]);
    }
}
