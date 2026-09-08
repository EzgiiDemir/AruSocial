<?php

namespace Tests\Feature;

use App\Models\RoleAssignment;
use App\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\CampusCatalogSeeder;
use Database\Seeders\TestAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TestAccountsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_seed_creates_the_three_portal_accounts_with_the_requested_password(): void
    {
        $this->seed(CampusCatalogSeeder::class);
        $this->seed(TestAccountsSeeder::class);

        $student = User::where('email', 'student@arucad.edu.tr')->firstOrFail();
        $trainer = User::where('email', 'trainer@arucad.edu.tr')->firstOrFail();
        $admin = User::where('email', 'admin@arucad.edu.tr')->firstOrFail();

        $this->assertTrue(Hash::check('password', $student->password));
        $this->assertTrue(Hash::check('password', $trainer->password));
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertSame('trainer', RoleAssignment::where('email', $trainer->email)->value('role'));
        $this->assertSame('superAdmin', RoleAssignment::where('email', $admin->email)->value('role'));

        $gmailStudent = User::where('email', 'ezgdemr02@gmail.com')->firstOrFail();
        $gmailTrainer = User::where('email', 'ezgidemir825@gmail.com')->firstOrFail();
        $this->assertTrue(Hash::check('password', $gmailStudent->password));
        $this->assertTrue(Hash::check('password', $gmailTrainer->password));
        $this->assertSame('trainer', RoleAssignment::where('email', $gmailTrainer->email)->value('role'));

        $staff = StaffProfile::find('staff-arch-head');
        $this->assertNotNull($staff);
        $this->assertSame($trainer->id, $staff->user_id);
        $this->assertSame('ezgidemir825@gmail.com', $staff->email);
    }
}
