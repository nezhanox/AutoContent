<?php

namespace App\Filament\Resources\Videos\Schemas;

use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

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
                Textarea::make('script_preview')
                    ->label('Script')
                    ->dehydrated(false)
                    ->disabled()
                    ->columnSpanFull()
                    ->afterStateHydrated(function (Textarea $component, ?Video $record): void {
                        $component->state($record?->script?->content);
                    }),
                Textarea::make('scenes_preview')
                    ->label('Scenes')
                    ->dehydrated(false)
                    ->disabled()
                    ->columnSpanFull()
                    ->afterStateHydrated(function (Textarea $component, ?Video $record): void {
                        if ($record === null) {
                            return;
                        }

                        $lines = $record->scenes->map(
                            fn ($scene): string => "#{$scene->order} [{$scene->type->value}] {$scene->duration}s — {$scene->visual_query}"
                        );

                        $component->state($lines->implode("\n"));
                    }),
                Placeholder::make('voiceover_preview')
                    ->label('Voiceover')
                    ->content(function (?Video $record): HtmlString {
                        if ($record?->voiceover?->file_path === null) {
                            return new HtmlString('—');
                        }

                        $url = Storage::disk(config('filesystems.default'))->url($record->voiceover->file_path);

                        return new HtmlString("<a href=\"{$url}\" target=\"_blank\" rel=\"noopener\">Play voiceover</a>");
                    }),
                Textarea::make('subtitles_preview')
                    ->label('Subtitles')
                    ->dehydrated(false)
                    ->disabled()
                    ->columnSpanFull()
                    ->afterStateHydrated(function (Textarea $component, ?Video $record): void {
                        if ($record?->subtitle?->path === null) {
                            return;
                        }

                        $component->state(Storage::disk(config('filesystems.default'))->get($record->subtitle->path));
                    }),
                Placeholder::make('render_preview')
                    ->label('Final video')
                    ->content(function (?Video $record): HtmlString {
                        if ($record?->file_path === null) {
                            return new HtmlString('—');
                        }

                        $url = Storage::disk(config('filesystems.default'))->url($record->file_path);

                        return new HtmlString("<a href=\"{$url}\" target=\"_blank\" rel=\"noopener\">Open rendered video</a>");
                    }),
                Textarea::make('error_message')
                    ->columnSpanFull(),
            ]);
    }
}
