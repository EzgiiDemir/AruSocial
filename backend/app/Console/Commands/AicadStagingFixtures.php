<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\FoodVenue;
use App\Models\OpeningHour;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Models\Sport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
 *   php artisan aicad:staging-fixtures          install (idempotent)
 *   php artisan aicad:staging-fixtures --remove remove every fixture row
 */
class AicadStagingFixtures extends Command
{
    protected $signature = 'aicad:staging-fixtures {--remove : Remove every fixture row instead}';

    protected $description = 'Install or remove labelled AICAD fixture records for a staging soak (never in production)';

    public const PREFIX = 'fixture-';

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
        });
        $this->info('Installed fixtures: [FIXTURE] Test Building, Test Office (phone), Test Cafe (place + Mon–Fri 08:00–16:00), Test Club (Instagram, e-mail, room), Test Team (place).');
        $this->line('Remove them after the soak: php artisan aicad:staging-fixtures --remove');

        return self::SUCCESS;
    }

    private function remove(): int
    {
        DB::transaction(function (): void {
            OpeningHour::query()->where('subject_id', 'like', self::PREFIX.'%')->delete();
            foreach ([Sport::class, Club::class, FoodVenue::class, ServiceItem::class, Place::class] as $model) {
                $model::query()->withTrashed()->where('id', 'like', self::PREFIX.'%')->forceDelete();
            }
        });
        $this->info('Removed every fixture row.');

        return self::SUCCESS;
    }
}
