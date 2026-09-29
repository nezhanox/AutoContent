<?php

namespace App\Filament\Resources\SourceChannels\Schemas;

use App\Models\ContentProject;
use App\Models\Enums\SourceChannelFraming;
use App\Models\Enums\SourceChannelMode;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SourceChannelForm
{
    public static function configure(Schema $schema): Schema
    {
        $clipFieldsVisible = fn (Get $get): bool => $get('mode') !== SourceChannelMode::Whole->value;

        return $schema
            ->components([
                Select::make('content_project_id')
                    ->label('Project')
                    ->options(fn (): array => ContentProject::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                // The URL is later passed to yt-dlp as a bare argument, so it must start with http(s)://
                // and contain no whitespace (a leading "-" would be option injection).
                TextInput::make('url')
                    ->required()
                    ->url()
                    ->regex('/\Ahttps?:\/\/\S+\z/')
                    ->maxLength(2048),
                TextInput::make('name')
                    ->maxLength(255),
                Select::make('mode')
                    ->options(self::enumOptions(SourceChannelMode::cases()))
                    ->default(SourceChannelMode::Highlights->value)
                    ->live()
                    ->required(),
                TextInput::make('target_seconds')
                    ->numeric()->integer()->minValue(1)->maxValue(3600)->default(60)
                    ->required()
                    ->visible($clipFieldsVisible),
                TextInput::make('tolerance_seconds')
                    ->numeric()->integer()->minValue(1)->maxValue(3600)->default(15)
                    ->required()
                    ->visible($clipFieldsVisible),
                TextInput::make('max_clips')
                    ->numeric()->integer()->minValue(1)->maxValue(50)->default(3)
                    ->required()
                    ->visible($clipFieldsVisible),
                TextInput::make('min_score')
                    ->numeric()->integer()->minValue(1)->maxValue(10)->default(6)
                    ->required()
                    ->visible($clipFieldsVisible),
                TextInput::make('max_source_minutes')
                    ->numeric()->integer()->minValue(1)->maxValue(1440)->default(120)
                    ->required(),
                Select::make('framing')
                    ->options(self::enumOptions(SourceChannelFraming::cases()))
                    ->default(SourceChannelFraming::BlurPad->value)
                    ->required(),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    /**
     * @param  array<int, \BackedEnum>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        return array_combine(
            array_map(fn (\BackedEnum $case): string => $case->value, $cases),
            array_map(fn (\BackedEnum $case): string => $case->name, $cases),
        );
    }
}
