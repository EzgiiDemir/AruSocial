<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fresh room hierarchy and 360 destinations without exposing the upstream
// Bearer token to the mobile app. `withoutOverlapping` protects the remote
// directory if a slow network response spans the next hourly invocation.
Schedule::command('campus:sync-360-directory')->hourly()->withoutOverlapping();

// Stories are a 24h promise. Reading is time-filtered so an expired story
// is already invisible; this is what actually makes it gone. Every fifteen
// minutes rather than daily so "expired" and "deleted" stay close together.
Schedule::command('stories:purge-expired')->everyFifteenMinutes()->withoutOverlapping();
