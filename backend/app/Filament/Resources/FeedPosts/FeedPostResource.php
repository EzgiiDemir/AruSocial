<?php

namespace App\Filament\Resources\FeedPosts;

use App\Filament\Resources\FeedPosts\Pages\ListFeedPosts;
use App\Filament\Resources\FeedPosts\Tables\FeedPostsTable;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\FeedPost;
use App\Models\User;
use App\Services\GranularPermissions;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FeedPostResource extends Resource
{
    protected static ?string $model = FeedPost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.social';

    protected static ?string $navigationLabel = 'panel.social.feed';

    protected static ?int $navigationSort = 10;

    private static function may(): bool
    {
        return auth()->user() instanceof User && GranularPermissions::allows(auth()->user(), 'moderation.moderate');
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
        return self::may();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return FeedPostsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListFeedPosts::route('/')];
    }
}
