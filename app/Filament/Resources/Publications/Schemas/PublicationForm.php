<?php

namespace App\Filament\Resources\Publications\Schemas;

use App\Models\Enums\PublicationStatus;
use App\Models\Video;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class PublicationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('video_id')
                    ->relationship('video', 'title')
                    ->searchable()
                    ->live()
                    ->required(),
                Select::make('social_account_id')
                    ->relationship(
                        name: 'socialAccount',
                        titleAttribute: 'username',
                        modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->when(
                            $get('video_id'),
                            fn (Builder $q, $videoId) => $q->where('content_project_id', Video::find($videoId)?->content_project_id)
                        ),
                    )
                    ->searchable()
                    ->required(),
                DateTimePicker::make('scheduled_at')
                    ->native(false)
                    ->helperText('Заповніть майбутньою датою — після збереження запис автоматично перейде у статус Scheduled.'),
                Textarea::make('caption')
                    ->columnSpanFull(),
                TagsInput::make('hashtags')
                    ->separator(',')
                    // TagsInput installs its own dehydrateStateUsing that joins the array into a
                    // comma-separated string whenever a separator is set — this override keeps
                    // the state a plain array so it round-trips through the jsonb column.
                    ->dehydrateStateUsing(fn ($state) => $state ?? []),
                Select::make('status')
                    ->options(PublicationStatus::class)
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Статус веде pipeline автоматично: Draft → Scheduled → Publishing → Published/Failed.'),
                TextInput::make('external_post_id')
                    ->disabled()
                    ->dehydrated(false),
                DateTimePicker::make('published_at')
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }
}
