<?php

namespace Tests;

use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Authenticates every subsequent HTTP call in the test as a real User
     * (docs/EKSIKLER.md "Gerçek JWT/session authentication") — every /v1
     * route now requires a real Sanctum bearer token, so feature tests need
     * a real authenticated identity instead of relying on the old "every
     * request is secretly the one seeded demo account" behavior.
     */
    protected function actingAsUser(
        string $name = 'Test Student',
        string $email = 'test@arucad.edu.tr',
        ?string $role = null,
    ): User {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('x')]);
        if ($role) {
            RoleAssignment::create([
                'email' => $email, 'role' => $role, 'assigned_by' => 'test', 'assigned_at' => now(),
            ]);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * A user with every admin permission (docs/EKSIKLER.md "RBAC permission
     * enforcement") — for tests validating admin *business logic*, not the
     * permission gate itself (see PermissionApiTest for that).
     */
    protected function actingAsAdmin(string $name = 'Test Admin', string $email = 'admin@arucad.edu.tr'): User
    {
        return $this->actingAsUser($name, $email, 'superAdmin');
    }
}
