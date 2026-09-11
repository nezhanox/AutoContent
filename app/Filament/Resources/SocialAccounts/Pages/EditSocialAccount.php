<?php

namespace App\Filament\Resources\SocialAccounts\Pages;

use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSocialAccount extends EditRecord
{
    protected static string $resource = SocialAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
