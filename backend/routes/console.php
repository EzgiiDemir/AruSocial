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
