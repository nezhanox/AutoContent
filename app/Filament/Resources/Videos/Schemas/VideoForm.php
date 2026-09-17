<?php

namespace App\Filament\Resources\Videos\Schemas;

use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class VideoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('content_project_id')
                    ->relationship('contentProject', 'name')
                    ->required(),
                Select::make('content_idea_id')
                    ->relationship('contentIdea', 'title')
                    ->required(),
                Select::make('script_id')
                    ->relationship('script', 'id')
                    ->required(),
                TextInput::make('title')
                    ->required(),
                Textarea::make('description')
                    ->required()
                    ->columnSpanFull(),
                Select::make('status')
                    ->options(VideoStatus::class)
                    ->required(),
                TextInput::make('duration')
                    ->numeric(),
                TextInput::make('width')
                    ->numeric(),
                TextInput::make('height')
                    ->numeric(),
                TextInput::make('file_path'),
                TextInput::make('thumbnail_path'),
                Select::make('music_asset_id')
                    ->label('Background Music')
                    ->relationship(
                        name: 'musicAsset',
                        titleAttribute: 'path',
                        modifyQueryUsing: fn ($query) => $query->where('type', MediaAssetType::Audio),
                    )
                    ->searchable()
                    ->preload(),
                Toggle::make('quality_passed')
                    ->label('Quality Passed')
                    ->disabled(),
                TextInput::make('metadata')
                    ->required()
                    ->default('{}')
                    ->disabled(),
                TextInput::make('quality_report')
                    ->label('Quality Report')
                    ->disabled()
                    ->formatStateUsing(fn ($state) => $state ? json_encode($state) : null),
                Textarea::make('error_message')
                    ->columnSpanFull(),
            ]);
    }
}
