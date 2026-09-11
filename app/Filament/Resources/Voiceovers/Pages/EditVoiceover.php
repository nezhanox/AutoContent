<?php

namespace App\Filament\Resources\Voiceovers\Pages;

use App\Filament\Resources\Voiceovers\VoiceoverResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVoiceover extends EditRecord
{
    protected static string $resource = VoiceoverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
