<?php

namespace App\Filament\Resources\RoleGrants\Schemas;

use App\Models\User;
use App\Services\GranularPermissions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class RoleGrantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.roles.assignment'))->schema([
                Select::make('user_id')->label(__('panel.roles.user'))->options(fn () => User::query()->orderBy('name')->pluck('email', 'id')->all())->searchable()->required(),
                Select::make('role')->label(__('panel.roles.role'))->options(function (): array {
                    $options = GranularPermissions::roleOptions();
                    if (! auth()->user() instanceof User || ! GranularPermissions::legacyAllows(auth()->user(), 'users.manage')) {
                        unset($options[GranularPermissions::SUPER_ROLE]);
                    }

                    return $options;
                })->searchable()->required(),
                Toggle::make('is_primary')->label(__('panel.roles.primary')),
                Select::make('status')->label(__('panel.users.status'))->options(['active' => __('panel.users.active'), 'suspended' => __('panel.users.suspended'), 'expired' => __('panel.roles.expired')])->default('active')->required(),
                Select::make('scope_type')->label(__('panel.roles.scope'))->options(array_combine(GranularPermissions::SCOPES, GranularPermissions::SCOPES))->default('all')->required(),
                TextInput::make('scope_id')->label(__('panel.roles.scope_id'))->helperText(__('panel.roles.scope_help'))
                    ->required(fn (Get $get): bool => ! in_array($get('scope_type'), ['all', 'self', 'own'], true)),
                DateTimePicker::make('starts_at')->label(__('panel.roles.starts_at')),
                DateTimePicker::make('expires_at')->label(__('panel.roles.expires_at'))->after('starts_at'),
            ])->columns(2),
            Section::make(__('panel.roles.capabilities'))->schema([
                Toggle::make('can_publish')->label(__('panel.roles.publish')),
                Toggle::make('can_export')->label(__('panel.roles.export')),
                Toggle::make('sensitive_data_access')->label(__('panel.roles.sensitive')),
                Select::make('permissions')->label(__('panel.roles.extra_permissions'))->options(GranularPermissions::permissionOptions())->multiple()->searchable()->columnSpanFull(),
                Select::make('denied_permissions')->label(__('panel.roles.denied_permissions'))->options(GranularPermissions::permissionOptions())->multiple()->searchable()->columnSpanFull(),
            ])->columns(3),
        ]);
    }
}
