<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\FoodVenue;
use App\Models\OpeningHour;
use App\Models\Place;
use App\Models\RoleAssignment;
use App\Models\ServiceItem;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Installs (or removes) a small set of clearly labelled FIXTURE records so a
 * staging soak can exercise "data filled" behaviour — phone, food hours and
 * place, club contacts and room, sports place — before staff have entered
 * real ARUCAD values.
 *
 * Fixtures are separate rows: ids start with `fixture-`, names with
 * "[FIXTURE]", e-mails use example.edu and phones +90 000. No real record
 * is changed, so nothing here can be mistaken for, or overwrite, verified
 * campus data. Refuses to run in production.
 *
 * It also creates three FIXTURE accounts for the two-user privacy smoke
 * (`ask:smoke --privacy`): two students with different, labelled personal
 * data (department; student A is a member of the fixture club) and one
 * staff account. Their passwords are random and never shown — the smoke
 * pack mints its own short-lived token — so no usable credential exists.
 *
 *   php artisan aicad:staging-fixtures          install (idempotent)
 *   php artisan aicad:staging-fixtures --remove remove every fixture row and account
 */
class AicadStagingFixtures extends Command
{
    protected $signature = 'aicad:staging-fixtures {--remove : Remove every fixture row instead}';

    protected $description = 'Install or remove labelled AICAD fixture records for a staging soak (never in production)';

    public const PREFIX = 'fixture-';

    /** Fixture account local parts; the domain is the deployment's allowed e-mail domain. */
    public const ACCOUNTS = ['student_a' => 'fixture-student-a', 'student_b' => 'fixture-student-b', 'staff' => 'fixture-staff'];

    /** Personal data only student A has — what must never reach anyone else. */
    public const STUDENT_A_MARKER = '[FIXTURE] Department A';

    public static function accountEmail(string $key): string
    {
        return self::ACCOUNTS[$key].(string) config('auth.allowed_email_domain', '@arucad.edu.tr');
    }

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production: fixtures are for staging and local soaks only.');

            return self::FAILURE;
        }

        return $this->option('remove') ? $this->remove() : $this->install();
    }

    private function install(): int
    {
        DB::transaction(function (): void {
            $place = Place::query()->withTrashed()->updateOrCreate(['id' => self::PREFIX.'place'], [
                'name' => '[FIXTURE] Test Building', 'category' => 'Fixture', 'lat' => 35.3380, 'lng' => 33.3220,
                'description' => 'Staging fixture: not a real ARUCAD place.', 'distance' => '', 'density' => 'quiet', 'street' => '', 'deleted_at' => null,
            ]);
            ServiceItem::query()->withTrashed()->updateOrCreate(['id' => self::PREFIX.'office'], [
                'title' => '[FIXTURE] Test Office', 'category' => 'Fixture', 'description' => 'Staging fixture: not a real ARUCAD office.',
                'contact' => 'fixture-office@example.edu', 'phone' => '+90 000 000 00 01', 'building' => $place->name,
                'hours' => 'Hafta içi 09:00–17:00', 'deleted_at' => null,
            ]);
            FoodVenue::query()->withTrashed()->updateOrCreate(['id' => self::PREFIX.'cafe'], [
                'name' => '[FIXTURE] Test Cafe', 'place_id' => $place->id, 'hours' => null, 'deleted_at' => null,
            ]);
            OpeningHour::query()->for('food_venue', self::PREFIX.'cafe')->delete();
            foreach (range(1, 5) as $day) {
                OpeningHour::create(['subject_type' => 'food_venue', 'subject_id' => self::PREFIX.'cafe', 'day_of_week' => $day, 'opens' => '08:00', 'closes' => '16:00']);
            }
            Club::query()->withTrashed()->updateOrCreate(['id' => self::PREFIX.'club'], [
                'name' => '[FIXTURE] Test Club', 'category' => 'Fixture', 'description' => 'Staging fixture: not a real ARUCAD club.',
                'instagram_url' => 'https://instagram.com/fixture_test_club', 'email' => 'fixture-club@example.edu', 'place_id' => $place->id, 'deleted_at' => null,
            ]);
            Sport::query()->withTrashed()->updateOrCreate(['id' => self::PREFIX.'team'], [
                'name' => '[FIXTURE] Test Team', 'facility' => '[FIXTURE] Test Hall', 'place_id' => $place->id, 'deleted_at' => null,
            ]);

            $profiles = ['student_a' => ['[FIXTURE] Student A', self::STUDENT_A_MARKER], 'student_b' => ['[FIXTURE] Student B', '[FIXTURE] Department B'],
                'staff' => ['[FIXTURE] Staff', null]];
            foreach ($profiles as $key => [$name, $department]) {
                $user = User::query()->firstOrNew(['email' => self::accountEmail($key)]);
                $user->fill(['name' => $name, 'department' => $department, 'year' => $department === null ? null : 2]);
                $user->password ??= Hash::make(Str::random(48));
                $user->save();
            }
            RoleAssignment::query()->updateOrCreate(['email' => self::accountEmail('staff')],
                ['role' => 'trainer', 'assigned_by' => 'aicad:staging-fixtures', 'assigned_at' => now()]);
            $studentA = User::query()->where('email', self::accountEmail('student_a'))->firstOrFail();
            ClubMember::query()->firstOrCreate(['user_id' => $studentA->id, 'club_id' => self::PREFIX.'club'], ['created_at' => now()]);
        });
        $this->info('Installed fixtures: [FIXTURE] Test Building, Test Office (phone), Test Cafe (place + Mon–Fri 08:00–16:00), Test Club (Instagram, e-mail, room), Test Team (place).');
        $this->info('Fixture accounts (no usable password): '.implode(', ', array_map(fn ($k) => self::accountEmail($k), array_keys(self::ACCOUNTS))).'.');
        $this->line('Remove them after the soak: php artisan aicad:staging-fixtures --remove');

        return self::SUCCESS;
    }

    private function remove(): int
    {
        DB::transaction(function (): void {
            $emails = array_map(fn ($k) => self::accountEmail($k), array_keys(self::ACCOUNTS));
            foreach (User::query()->whereIn('email', $emails)->get() as $user) {
                $user->tokens()->delete();
                ClubMember::query()->where('user_id', $user->id)->delete();
                $user->forceDelete();
            }
            RoleAssignment::query()->whereIn('email', $emails)->delete();
            ClubMember::query()->where('club_id', 'like', self::PREFIX.'%')->delete();
            OpeningHour::query()->where('subject_id', 'like', self::PREFIX.'%')->delete();
            foreach ([Sport::class, Club::class, FoodVenue::class, ServiceItem::class, Place::class] as $model) {
                $model::query()->withTrashed()->where('id', 'like', self::PREFIX.'%')->forceDelete();
            }
        });
        $this->info('Removed every fixture row and fixture account.');

        return self::SUCCESS;
    }
}
