<?php

namespace App\Services;

use App\Models\User;

class PrivateProfileGate
{
    public static function allows(User $viewer, User $owner): bool
    {
        if ((int) $viewer->id === (int) $owner->id) {
            return true;
        }
        if (! $owner->is_private_profile) {
            return true;
        }
        if (GranularPermissions::allows($viewer, 'users.manage')
            || GranularPermissions::allows($viewer, 'moderation.moderate')) {
            return true;
        }

        return $owner->followers()->where('users.id', $viewer->id)->exists();
    }

    public static function isFriend(User $a, User $b): bool
    {
        if ((int) $a->id === (int) $b->id) {
            return false;
        }

        return $a->following()->where('users.id', $b->id)->exists()
            && $b->following()->where('users.id', $a->id)->exists();
    }
}
