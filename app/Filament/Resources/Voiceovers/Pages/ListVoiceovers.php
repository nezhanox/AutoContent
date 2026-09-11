<?php

namespace App\Filament\Resources\Voiceovers\Pages;

use App\Filament\Resources\Voiceovers\VoiceoverResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVoiceovers extends ListRecords
{
    protected static string $resource = VoiceoverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
