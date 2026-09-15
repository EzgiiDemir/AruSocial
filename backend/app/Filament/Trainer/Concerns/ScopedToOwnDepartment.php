<?php

namespace App\Filament\Trainer\Concerns;

use App\Models\StaffProfile;
use App\Models\User;
use App\Services\GranularPermissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * A trainer sees their own department's rows and nobody else's.
 *
 * Row-level scoping, applied in the query rather than checked per action:
 * a permission check answers "may this person edit events", which is yes,
 * and would happily let them edit somebody else's. The scope answers the
 * question that actually matters — *which* events — and it cannot be
 * bypassed by guessing an id, because the row is not in the result set to
 * begin with.
 *
 * This mirrors `EnsureDepartmentHead` and `Api\Trainer\EventController`,
 * which scope the JSON API the same way, on the same column. Two surfaces
 * onto the same data have to agree about who owns a row, or the panel
 * becomes the way around the API's rules.
 *
 * A trainer with no active staff profile sees nothing at all rather than
 * everything — the failure direction matters, and `whereRaw('0 = 1')` is
 * the safe one.
 */
trait ScopedToOwnDepartment
{
    /**
     * The column on this resource's model that names the owning staff
     * member. Declared per resource because it is not always the same.
     */
    abstract public static function ownerColumn(): string;

    public static function staffProfile(): ?StaffProfile
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }

        return StaffProfile::where('user_id', $user->id)
            ->where('active', true)
            ->first();
    }

    protected static function mayManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && GranularPermissions::allows($user, 'events.manageOwnDepartment')
            && static::staffProfile() !== null;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $staff = static::staffProfile();

        // No staff profile, no rows. Returning the unscoped query here
        // would hand a trainer the whole university the moment their
        // profile was deactivated.
        if ($staff === null) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(static::ownerColumn(), $staff->id);
    }

    /**
     * Route binding runs through the same scope, so a trainer who types
     * another department's id into the URL gets a 404 rather than a form.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        $staff = static::staffProfile();

        $query = parent::getRecordRouteBindingEloquentQuery();

        return $staff === null
            ? $query->whereRaw('0 = 1')
            : $query->where(static::ownerColumn(), $staff->id);
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
}
