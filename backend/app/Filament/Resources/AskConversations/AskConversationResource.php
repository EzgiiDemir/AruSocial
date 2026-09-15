<?php

namespace App\Filament\Resources\AskConversations;

use App\Filament\Resources\AskConversations\Pages\ListAskConversations;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\AskConversation;
use App\Models\User;
use App\Services\GranularPermissions;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class AskConversationResource extends Resource
{
    protected static ?string $model = AskConversation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.aicad';

    protected static ?string $navigationLabel = 'panel.ai.chat_history';

    protected static ?int $navigationSort = 40;

    private static function may(): bool
    {
        $u = auth()->user();

        return $u instanceof User && (GranularPermissions::allows($u, 'ai.chat_history_metadata.read') || GranularPermissions::allows($u, 'users.manage'));
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
        return $table->defaultSort('updated_at', 'desc')->columns([
            TextColumn::make('id')->label('ID')->copyable()->toggleable(),
            TextColumn::make('user.email')->label(__('panel.ai.user'))->searchable(),
            TextColumn::make('title')->label(__('panel.ai.title'))->searchable(),
            TextColumn::make('messages_count')->counts('messages')->label(__('panel.ai.message_count')),
            TextColumn::make('created_at')->label(__('panel.ai.created'))->dateTime(),
            TextColumn::make('updated_at')->label(__('panel.ai.updated'))->dateTime()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAskConversations::route('/')];
    }
}
