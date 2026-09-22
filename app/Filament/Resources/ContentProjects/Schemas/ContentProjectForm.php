<?php

namespace App\Filament\Resources\ContentProjects\Schemas;

use App\Domain\Video\Services\ListElevenLabsVoicesService;
use App\Models\Enums\SocialPlatform;
use Filament\Forms\Components\CheckboxList;
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
                Select::make('settings.tts.voice')
                    ->label('Default voice (ElevenLabs)')
                    ->options(fn (): array => app(ListElevenLabsVoicesService::class)->options())
                    ->searchable(),
                CheckboxList::make('target_platforms')
                    ->label('Publish platforms')
                    ->options(array_combine(
                        array_map(fn (SocialPlatform $platform): string => $platform->value, SocialPlatform::cases()),
                        array_map(fn (SocialPlatform $platform): string => $platform->name, SocialPlatform::cases()),
                    ))
                    ->required(),
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
