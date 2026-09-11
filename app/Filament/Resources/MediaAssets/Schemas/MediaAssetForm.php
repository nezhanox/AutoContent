<?php

namespace App\Filament\Resources\MediaAssets\Schemas;

use App\Models\Enums\MediaAssetType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MediaAssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->options(MediaAssetType::class)
                    ->required(),
                TextInput::make('provider')
                    ->required(),
                TextInput::make('path')
                    ->required(),
                TextInput::make('mime_type')
                    ->required(),
                TextInput::make('width')
                    ->numeric(),
                TextInput::make('height')
                    ->numeric(),
                TextInput::make('duration')
                    ->numeric(),
                TextInput::make('metadata')
                    ->required()
                    ->default('{}'),
                TextInput::make('hash')
                    ->required(),
            ]);
    }
}
