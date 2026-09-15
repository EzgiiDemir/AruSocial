<?php

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\GranularPermissions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * The behaviour every campus-content resource has to have.
 *
 * The brief asks for the same seven things on each of them — listing,
 * search, filter, create, view, edit, soft delete with restore, bulk
 * actions, export, audit logging. Written out per resource that is the
 * same forty lines twenty times, and the copy that gets missed is the one
 * where a delete goes unaudited or an unprivileged account keeps an Edit
 * button.
 *
 * A resource using this declares one thing — which permission key it sits
 * behind — and gets the rest.
 *
 * Authorisation defers to `GranularPermissions`, the same service the JSON
 * API and `EnsurePermission` already use, so a role change is made in one
 * place and the panel follows. A resource that writes its own rule is a
 * resource whose access story is invisible from the role matrix.
 *
 * Using this trait requires the model to use `SoftDeletes`; the restore and
 * force-delete actions and the trashed filter all depend on it.
 */
trait ManagesCampusContent
{
    /**
     * The `GranularPermissions` key this resource sits behind.
     *
     * Declared by each resource. There is no default on purpose — a
     * resource that forgets to name one should fail loudly rather than
     * inherit somebody else's access level.
     */
    abstract public static function permissionKey(): string;

    /**
     * What the audit trail calls rows from this resource.
     */
    public static function auditType(): string
    {
        return str(class_basename(static::$model ?? static::class))->snake()->toString();
    }

    /**
     * How a row names itself in the audit trail and in confirmations.
     *
     * `name` and `title` cover almost every table here; the key is the
     * honest fallback for the ones with neither, since an audit line
     * reading "deleted" with no subject is not a record of anything.
     */
    public static function recordLabel(Model $record): string
    {
        foreach (['name', 'title', 'label', 'key'] as $attribute) {
            $value = $record->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return (string) $record->getKey();
    }

    // ---- authorisation -------------------------------------------------

    protected static function mayManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && GranularPermissions::allows($user, static::permissionKey());
    }

    public static function canViewAny(): bool
    {
        return static::mayManage();
    }

    public static function canView($record): bool
    {
        return static::mayManage();
    }

    public static function canCreate(): bool
    {
        return static::mayManage();
    }

    public static function canEdit($record): bool
    {
        return static::mayManage();
    }

    public static function canDelete($record): bool
    {
        return static::mayManage();
    }

    public static function canDeleteAny(): bool
    {
        return static::mayManage();
    }

    public static function canRestore($record): bool
    {
        return static::mayManage();
    }

    public static function canForceDelete($record): bool
    {
        return static::mayManage();
    }

    // ---- queries -------------------------------------------------------

    /**
     * Deleted rows are excluded by the model's own scope, which is what
     * makes the panel agree with the app. Dropping the scope here lets the
     * trashed filter ask for them back — nothing else does.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }

    /**
     * Route binding has to see deleted rows too, or the Restore button in
     * the trashed listing links to a 404.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }

    // ---- actions -------------------------------------------------------

    /**
     * View, edit, delete, restore, purge — with every destructive one
     * recorded.
     *
     * Restore is on the row rather than a separate screen so that undoing a
     * mis-click is one click from where it happened, which is where someone
     * looks for it.
     */
    public static function contentRecordActions(): array
    {
        return [
            ViewAction::make(),
            EditAction::make(),

            DeleteAction::make()
                ->after(fn (Model $record) => static::audit('delete', $record)),

            RestoreAction::make()
                ->after(fn (Model $record) => static::audit('restore', $record)),

            // The one that cannot be taken back, so it says so and is not
            // offered until the row is already deleted.
            ForceDeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading(__('panel.common.purge'))
                ->modalDescription(__('panel.common.purge_body'))
                ->after(fn (Model $record) => static::audit('purge', $record)),
        ];
    }

    public static function contentToolbarActions(): array
    {
        return [
            BulkActionGroup::make([
                DeleteBulkAction::make(),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make()
                    ->requiresConfirmation()
                    ->modalHeading(__('panel.common.purge_bulk')),
                ExportBulkAction::make(),
            ]),
        ];
    }

    /**
     * Shows live rows by default, with deleted ones a filter away.
     */
    public static function contentFilters(): array
    {
        return [
            TrashedFilter::make(),
        ];
    }

    private static function audit(string $action, Model $record): void
    {
        AuditLogger::logAsCurrentUser(
            $action,
            static::auditType(),
            static::recordLabel($record),
        );
    }
}
