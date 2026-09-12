<?php

namespace App\Filament\Resources\ContentProjects\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ContentProjectForm
{
    private const PURPOSES = [
        'default' => 'Default',
        'idea' => 'Idea generation',
        'script' => 'Script generation',
        'quality_check' => 'Quality check',
        'captions' => 'Captions/hashtags',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('niche')
                    ->required(),
                TextInput::make('language')
                    ->required(),
                TextInput::make('target_platforms')
                    ->required()
                    ->default('[]')
                    ->disabled(),
                TextInput::make('status')
                    ->required(),
                ...static::aiSettingsFields(),
            ]);
    }

    /**
     * @return array<int, Select|TextInput>
     */
    private static function aiSettingsFields(): array
    {
        $providers = array_diff(array_keys(config('llm.providers')), ['fake', 'fake_secondary']);
        $providerOptions = array_combine($providers, $providers);

        $fields = [];

        foreach (self::PURPOSES as $purpose => $label) {
            $fields[] = Select::make("settings.ai.{$purpose}.provider")
                ->label("{$label} — provider")
                ->options($providerOptions);

            $fields[] = TextInput::make("settings.ai.{$purpose}.model")
                ->label("{$label} — model");
        }

        return $fields;
    }
}
