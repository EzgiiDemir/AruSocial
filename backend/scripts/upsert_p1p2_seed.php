<?php

/**
 * Idempotent upsert of full campus POI + staff catalog without wipe.
 * Prefer: php artisan db:seed --class=CampusCatalogSeeder
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$seeder = new Database\Seeders\CampusCatalogSeeder();
$seeder->run();

echo \App\Models\StaffProfile::where('is_department_head', true)->count()
    .' heads; '
    .\App\Models\Place::count()
    ." places\n";
