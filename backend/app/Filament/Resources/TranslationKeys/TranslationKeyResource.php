<?php

namespace App\Filament\Resources\TranslationKeys;

use App\Filament\Resources\TranslationKeys\Pages\CreateTranslationKey;
use App\Filament\Resources\TranslationKeys\Pages\EditTranslationKey;
use App\Filament\Resources\TranslationKeys\Pages\ListTranslationKeys;
use App\Filament\Resources\TranslationKeys\Schemas\TranslationKeyForm;
use App\Filament\Resources\TranslationKeys\Tables\TranslationKeysTable;
use App\Models\TranslationKey;
use App\Models\User;
use App\Services\GranularPermissions;
use App\Services\Translations\TranslationCatalogue;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The app's words, editable without a release.
 *
 * Every permission check defers to `GranularPermissions`, the same service
 * the JSON API and both panels already use — a resource that invents its
 * own rule is a resource whose access story is invisible from the role
 * matrix.
 *
 * Translations are gated on `manageSiteSettings` (super admin) by default
 * rather than general content editing, because publishing here changes what
 * every installed phone displays, immediately and without review. If you
 * want a dedicated translator role, this is the one line to change — see
 * §4.2 of the approval checklist.
 */
class TranslationKeyResource extends Resource
{
    protected static ?string $model = TranslationKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static ?string $navigationLabel = 'panel.translations.nav';

    protected static ?string $modelLabel = 'translation';

    protected static ?string $pluralModelLabel = 'translations';

    protected static ?int $navigationSort = 90;

    public static function form(Schema $schema): Schema
    {
        return TranslationKeyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TranslationKeysTable::configure($table);
    }

    /**
     * How many strings are missing a translation, shown on the navigation
     * item itself.
     *
     * This is the "missing-translation warning in the Admin panel" the brief
     * asks for: it is visible without opening anything, which is the only
     * form of warning that gets acted on.
     */
    public static function getNavigationBadge(): ?string
    {
        $missing = collect(TranslationCatalogue::missing())
            ->flatten()
            ->count();

        return $missing > 0 ? (string) $missing : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('panel.translations.missing_badge');
    }

    private static function may(string $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && GranularPermissions::allows($user, $permission);
    }

    public static function canViewAny(): bool
    {
        return self::may('users.manage');
    }

    public static function canCreate(): bool
    {
        return self::may('users.manage');
    }

    public static function canEdit($record): bool
    {
        return self::may('users.manage');
    }

    public static function canDelete($record): bool
    {
        return self::may('users.manage');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTranslationKeys::route('/'),
            'create' => CreateTranslationKey::route('/create'),
            'edit' => EditTranslationKey::route('/{record}/edit'),
        ];
    }
}
