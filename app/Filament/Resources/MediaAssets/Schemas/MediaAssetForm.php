<?php

namespace App\Filament\Resources\MediaAssets\Schemas;

use App\Models\Enums\MediaAssetType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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
                    ->required()
                    ->default('local'),
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
                TagsInput::make('metadata.tags')
                    ->label('Tags')
                    ->helperText('Ключові слова для пошуку через LocalAssetProvider (порівнюються з visual_query сцени). Використовуйте однослівні теги нижнього регістру — багатослівні теги ніколи не збігаються.')
                    ->separator(',')
                    // Filament's TagsInput installs its own dehydrateStateUsing that joins the
                    // array into a comma-separated string whenever a separator is set — this
                    // override keeps the state a plain array so it round-trips through
                    // metadata.tags correctly.
                    ->dehydrateStateUsing(fn ($state) => $state),
                TextInput::make('hash')
                    ->required(),
            ]);
    }
}
