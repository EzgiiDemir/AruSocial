<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.users.identity'))->schema([
                TextInput::make('name')->label(__('panel.users.name'))->required()->maxLength(160),
                TextInput::make('email')->label(__('panel.users.email'))->email()->required()->unique(ignoreRecord: true),
                TextInput::make('password')->password()->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->afterStateHydrated(fn (TextInput $component): TextInput => $component->state(''))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->minLength(8)
                    ->autocomplete('new-password'),
                TextInput::make('phone')->tel()->label(__('panel.users.phone')),
                TextInput::make('institution_id')->label(__('panel.users.institution_id')),
                TextInput::make('job_title')->label(__('panel.users.job_title')),
                Select::make('preferred_language')->label(__('panel.users.language'))->options(['TR' => 'Türkçe', 'EN' => 'English', 'RU' => 'Русский'])->default('TR'),
                TextInput::make('timezone')->label(__('panel.users.timezone'))->default('Europe/Nicosia'),
                Select::make('account_status')->label(__('panel.users.status'))->options([
                    'invitation_pending' => __('panel.users.invitation_pending'),
                    'active' => __('panel.users.active'), 'suspended' => __('panel.users.suspended'),
                    'locked' => __('panel.users.locked'), 'archived' => __('panel.users.archived'),
                ])->default('active')->required(),
            ])->columns(2),
            Section::make(__('panel.users.organization'))->schema([
                TextInput::make('campus')->label(__('panel.users.campus')),
                TextInput::make('faculty')->label(__('panel.users.faculty')),
                TextInput::make('department')->label(__('panel.users.department')),
                TextInput::make('unit')->label(__('panel.users.unit')),
                TextInput::make('building')->label(__('panel.users.building')),
            ])->columns(2),
            Section::make(__('panel.users.security'))->schema([
                Toggle::make('mfa_required')->label(__('panel.users.require_mfa')),
            ]),
        ]);
    }
}
