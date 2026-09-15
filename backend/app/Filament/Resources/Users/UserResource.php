<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\TranslatedResource as Resource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use App\Services\GranularPermissions;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.users';

    protected static ?string $navigationLabel = 'panel.users.nav';

    protected static ?int $navigationSort = 10;

    private static function mayManage(string $action = 'list'): bool
    {
        return auth()->user() instanceof User && (
            GranularPermissions::allows(auth()->user(), 'users.manage')
            || GranularPermissions::allows(auth()->user(), 'users.user.'.$action)
        );
    }

    public static function canViewAny(): bool
    {
        return self::mayManage('list');
    }

    public static function canView($record): bool
    {
        return self::mayManage('read');
    }

    public static function canCreate(): bool
    {
        return self::mayManage('create');
    }

    public static function canEdit($record): bool
    {
        return self::mayManage('update');
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'), 'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'), 'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
