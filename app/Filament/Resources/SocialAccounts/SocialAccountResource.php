<?php

namespace App\Filament\Resources\SocialAccounts;

use App\Filament\Resources\SocialAccounts\Pages\CreateSocialAccount;
use App\Filament\Resources\SocialAccounts\Pages\EditSocialAccount;
use App\Filament\Resources\SocialAccounts\Pages\ListSocialAccounts;
use App\Filament\Resources\SocialAccounts\Schemas\SocialAccountForm;
use App\Filament\Resources\SocialAccounts\Tables\SocialAccountsTable;
use App\Models\SocialAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SocialAccountResource extends Resource
{
    protected static ?string $model = SocialAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Publishing';

    public static function canCreate(): bool
    {
        // `social_accounts.access_token` is NOT NULL and is deliberately absent from the
        // form — tokens arrive via OAuth in a later phase — so a hand-made Create could
        // only ever fail with a not-null constraint violation.
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return SocialAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SocialAccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSocialAccounts::route('/'),
            'create' => CreateSocialAccount::route('/create'),
            'edit' => EditSocialAccount::route('/{record}/edit'),
        ];
    }
}
