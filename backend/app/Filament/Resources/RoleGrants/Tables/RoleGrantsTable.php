<?php

namespace App\Filament\Resources\RoleGrants\Tables;

use App\Models\RoleGrant;
use App\Services\AuditLogger;
use App\Services\GranularPermissions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RoleGrantsTable
{
    public static function configure(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('user.name')->label(__('panel.roles.user'))->searchable()->description(fn (RoleGrant $record) => $record->user?->email),
            TextColumn::make('role')->label(__('panel.roles.role'))->badge()->searchable()->sortable(),
            IconColumn::make('is_primary')->label(__('panel.roles.primary'))->boolean(),
            TextColumn::make('scope_type')->label(__('panel.roles.scope'))->badge(),
            TextColumn::make('scope_id')->label(__('panel.roles.scope_id'))->placeholder('—'),
            TextColumn::make('status')->label(__('panel.users.status'))->badge(),
            TextColumn::make('expires_at')->label(__('panel.roles.expires_at'))->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('role')->options(GranularPermissions::roleOptions()),
            SelectFilter::make('scope_type')->options(array_combine(GranularPermissions::SCOPES, GranularPermissions::SCOPES)),
            SelectFilter::make('status')->options(['active' => __('panel.users.active'), 'suspended' => __('panel.users.suspended'), 'expired' => __('panel.roles.expired')]),
        ])->recordActions([
            ViewAction::make(), EditAction::make(),
            DeleteAction::make()->after(fn (RoleGrant $record) => AuditLogger::logAsCurrentUser('delete', 'role_grant', $record->user?->email.' / '.$record->role)),
        ])->toolbarActions([BulkActionGroup::make([ExportBulkAction::make()])]);
    }
}
