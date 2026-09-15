<?php

namespace App\Services;

use App\Models\RoleGrant;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class ScopedAccess
{
    public static function apply(Builder $query, User $user, string $permission): Builder
    {
        if (GranularPermissions::legacyAllows($user, $permission)) {
            return $query;
        }

        $grants = RoleGrant::query()->active()->where('user_id', $user->id)->get()
            ->filter(fn (RoleGrant $grant) => GranularPermissions::grantAllows($grant, $permission));
        if ($grants->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }
        if ($grants->contains(fn (RoleGrant $grant) => $grant->scope_type === 'all' || $grant->role === GranularPermissions::SUPER_ROLE)) {
            return $query;
        }

        $model = $query->getModel();
        $table = $model->getTable();
        $columns = Schema::getColumnListing($table);

        return $query->where(function (Builder $outer) use ($grants, $user, $columns, $table): void {
            $applied = false;
            foreach ($grants as $grant) {
                $scopeId = $grant->scope_id;
                $matched = self::appendScope($outer, $grant->scope_type, $scopeId, $user, $columns, $table, $applied);
                $applied = $applied || $matched;
            }
            if (! $applied) {
                $outer->whereRaw('1 = 0');
            }
        });
    }

    private static function appendScope(Builder $query, string $scope, ?string $id, User $user, array $columns, string $table, bool $hasPrevious): bool
    {
        $method = $hasPrevious ? 'orWhere' : 'where';
        $column = null;
        $value = $id;

        if (in_array($scope, ['self', 'own'], true)) {
            foreach (['user_id', 'author_id', 'created_by', 'updated_by'] as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    $column = $candidate;
                    break;
                }
            }
            $value = in_array($column, ['created_by', 'updated_by'], true) ? $user->email : $user->id;
        } elseif ($scope === 'own_club') {
            $column = in_array('club_id', $columns, true) ? 'club_id' : ($table === 'clubs' ? 'id' : null);
        } elseif ($scope === 'own_faculty' && in_array('faculty', $columns, true)) {
            $column = 'faculty';
            $value ??= $user->faculty;
        } elseif ($scope === 'own_building') {
            $column = in_array('building_id', $columns, true) ? 'building_id' : (in_array('building', $columns, true) ? 'building' : null);
            $value ??= $user->building;
        } elseif ($scope === 'campus') {
            $column = in_array('campus_id', $columns, true) ? 'campus_id' : (in_array('campus', $columns, true) ? 'campus' : null);
            $value ??= $user->campus;
        } elseif ($scope === 'own_department') {
            if (in_array('department', $columns, true)) {
                $column = 'department';
                $value ??= $user->department;
            } elseif (in_array('responsible_staff_id', $columns, true) && filled($value ?? $user->department)) {
                $staffIds = StaffProfile::query()->where('department', $value ?? $user->department)->pluck('id');
                $query->{$hasPrevious ? 'orWhereIn' : 'whereIn'}('responsible_staff_id', $staffIds);

                return true;
            }
        } elseif ($scope === 'assigned' && in_array('responsible_staff_id', $columns, true)) {
            $staffIds = StaffProfile::query()->where('user_id', $user->id)->pluck('id');
            $query->{$hasPrevious ? 'orWhereIn' : 'whereIn'}('responsible_staff_id', $staffIds);

            return true;
        }

        if ($column === null || blank($value)) {
            return false;
        }
        $query->{$method}($column, $value);

        return true;
    }

    public static function recordAllowed(Model $record, User $user, string $permission): bool
    {
        return self::apply($record->newQuery(), $user, $permission)->whereKey($record->getKey())->exists();
    }
}
