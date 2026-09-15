<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            TextColumn::make('name')->label(__('panel.users.name'))->searchable()->sortable()->weight('medium'),
            TextColumn::make('email')->label(__('panel.users.email'))->searchable()->copyable(),
            TextColumn::make('account_status')->label(__('panel.users.status'))->badge()->sortable(),
            TextColumn::make('department')->label(__('panel.users.department'))->searchable()->toggleable(),
            TextColumn::make('preferred_language')->label(__('panel.users.language'))->badge(),
            TextColumn::make('role_grants_count')->counts('roleGrants')->label(__('panel.users.roles')),
            TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('account_status')->options([
                'invitation_pending' => __('panel.users.invitation_pending'), 'active' => __('panel.users.active'),
                'suspended' => __('panel.users.suspended'), 'locked' => __('panel.users.locked'), 'archived' => __('panel.users.archived'),
            ]),
            SelectFilter::make('preferred_language')->options(['TR' => 'TR', 'EN' => 'EN', 'RU' => 'RU']),
        ])->recordActions([
            ViewAction::make(), EditAction::make(),
            Action::make('suspend')->label(__('panel.users.suspend'))->icon('heroicon-o-no-symbol')->color('danger')
                ->requiresConfirmation()->visible(fn (User $record) => $record->id !== auth()->id() && $record->account_status === 'active')
                ->action(function (User $record): void {
                    $record->update(['account_status' => 'suspended']);
                    $record->tokens()->delete();
                    AuditLogger::logAsCurrentUser('suspend', 'user', $record->email);
                    Notification::make()->title(__('panel.users.suspended_notice'))->success()->send();
                }),
            Action::make('terminate_sessions')->label(__('panel.users.terminate_sessions'))->icon('heroicon-o-arrow-right-on-rectangle')
                ->requiresConfirmation()->visible(fn (User $record) => $record->id !== auth()->id())
                ->action(function (User $record): void {
                    $record->tokens()->delete();
                    AuditLogger::logAsCurrentUser('terminate_sessions', 'user', $record->email);
                }),
        ])->toolbarActions([BulkActionGroup::make([ExportBulkAction::make()])]);
    }
}
