<?php

namespace Database\Seeders;

use App\Models\RoleAssignment;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

// Real, working accounts for manual end-to-end testing across the Student
// app, Admin Panel, and Trainer Panel. This is part of the local seed
// chain so a fresh development database is usable immediately. It is
// intentionally blocked outside local/testing; production accounts must
// come from the identity provider or a controlled provisioning process.
class TestAccountsSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Test accounts are only seeded in local/testing.');

            return;
        }

        // Campus-domain portal accounts — tests and scripts depend on these
        // exact addresses. Always built from the singular domain key.
        $domain = (string) config('auth.allowed_email_domain');

        // Plain student — no RoleAssignment row, defaults to 'student'.
        $student = User::updateOrCreate(
            ['email' => "student{$domain}"],
            ['name' => 'Test Student', 'password' => self::PASSWORD],
        );

        // Full admin — every admin panel section.
        $admin = User::updateOrCreate(
            ['email' => "admin{$domain}"],
            ['name' => 'Test Admin', 'password' => self::PASSWORD],
        );
        RoleAssignment::updateOrCreate(
            ['email' => $admin->email],
            ['role' => 'superAdmin', 'assigned_by' => 'TestAccountsSeeder', 'assigned_at' => now()],
        );

        // Trainer — campus-domain account kept for tests/scripts.
        $trainer = User::updateOrCreate(
            ['email' => "trainer{$domain}"],
            ['name' => 'Test Trainer', 'password' => self::PASSWORD],
        );
        RoleAssignment::updateOrCreate(
            ['email' => $trainer->email],
            ['role' => 'trainer', 'assigned_by' => 'TestAccountsSeeder', 'assigned_at' => now()],
        );

        // Real Gmail inboxes for local SMTP smoke tests (override via .env).
        $gmailStudentEmail = (string) env('TEST_STUDENT_EMAIL', 'ezgdemr02@gmail.com');
        $gmailTrainerEmail = (string) env('TEST_TRAINER_EMAIL', 'ezgidemir825@gmail.com');

        $gmailStudent = User::updateOrCreate(
            ['email' => $gmailStudentEmail],
            ['name' => 'Ezgi Student', 'password' => self::PASSWORD],
        );

        $gmailTrainer = User::updateOrCreate(
            ['email' => $gmailTrainerEmail],
            ['name' => 'Ezgi Trainer', 'password' => self::PASSWORD],
        );
        RoleAssignment::updateOrCreate(
            ['email' => $gmailTrainer->email],
            ['role' => 'trainer', 'assigned_by' => 'TestAccountsSeeder', 'assigned_at' => now()],
        );

        // Architecture department head — linked to the real Gmail trainer
        // so application mail and Trainer Panel publish under that inbox.
        $staff = StaffProfile::find('staff-arch-head');
        if ($staff !== null) {
            $staff->update([
                'user_id' => $gmailTrainer->id,
                'email' => $gmailTrainer->email,
                'is_department_head' => true,
                'active' => true,
            ]);
        }

        $this->command?->info('Test accounts ready (password for all: '.self::PASSWORD.'):');
        $this->command?->info("  {$student->email}");
        $this->command?->info("  {$admin->email}");
        $this->command?->info("  {$trainer->email}");
        $this->command?->info("  {$gmailStudent->email} (Gmail student)");
        $this->command?->info("  {$gmailTrainer->email} (Gmail trainer / department head)".(
            $staff === null ? ' (WARNING: staff-arch-head not found — run CampusCatalogSeeder first)' : ''
        ));
    }
}
