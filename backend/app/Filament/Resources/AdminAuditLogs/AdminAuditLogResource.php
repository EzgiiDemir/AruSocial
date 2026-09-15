<?php

namespace App\Filament\Resources\AdminAuditLogs;

use App\Filament\Resources\AdminAuditLogs\Pages\ListAdminAuditLogs;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\GranularPermissions;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class AdminAuditLogResource extends Resource
{
    protected static ?string $model = AdminAuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.system';

    protected static ?string $navigationLabel = 'panel.system.audit_logs';

    protected static ?int $navigationSort = 30;

    private static function may(): bool
    {
        return auth()->user() instanceof User && GranularPermissions::allows(auth()->user(), 'activityLog.view');
    }

    public static function canViewAny(): bool
    {
        return self::may();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('at', 'desc')->columns([
            TextColumn::make('actor_name')->label(__('panel.system.actor'))->searchable(), TextColumn::make('action')->label(__('panel.system.action'))->badge()->searchable(),
            TextColumn::make('target_type')->label(__('panel.system.target_type'))->searchable(), TextColumn::make('target_label')->label(__('panel.system.target'))->searchable()->wrap(),
            TextColumn::make('at')->label(__('panel.system.time'))->dateTime()->sortable(),
        ])->filters([SelectFilter::make('action')->options(fn () => AdminAuditLog::query()->distinct()->pluck('action', 'action')->all())]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAdminAuditLogs::route('/')];
    }
}
