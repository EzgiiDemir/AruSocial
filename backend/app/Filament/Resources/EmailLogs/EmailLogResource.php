<?php

namespace App\Filament\Resources\EmailLogs;

use App\Filament\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\EmailLog;
use App\Models\User;
use App\Services\GranularPermissions;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class EmailLogResource extends Resource
{
    protected static ?string $model = EmailLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.system';

    protected static ?string $navigationLabel = 'panel.system.email_logs';

    protected static ?int $navigationSort = 10;

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
        return $table->defaultSort('sent_at', 'desc')->columns([
            TextColumn::make('to_email')->label(__('panel.system.recipient'))->searchable(), TextColumn::make('template')->label(__('panel.system.template'))->searchable(),
            TextColumn::make('subject')->label(__('panel.system.subject'))->limit(70), TextColumn::make('status')->label(__('panel.users.status'))->badge(),
            TextColumn::make('attempts')->label(__('panel.system.attempts')), TextColumn::make('error')->label(__('panel.system.failure'))->limit(80)->toggleable(),
            TextColumn::make('sent_at')->label(__('panel.system.sent_at'))->dateTime()->sortable(),
        ])->filters([SelectFilter::make('status')->options(['sent' => 'Sent', 'failed' => 'Failed'])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListEmailLogs::route('/')];
    }
}
