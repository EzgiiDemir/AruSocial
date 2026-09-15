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

// Every failure this catches is invisible from the outside. A stopped
// classifier makes every upload fail closed with a 503, which reads to a
// student as "my photo was rejected"; held content with no moderator
// draining the queue looks like a successful upload that never appears.
//
// Hourly, because the two things it watches move on that scale: a service
// that has crashed, and a review queue going stale. The command writes an
// error log and raises a Sentry event when it finds a problem, so this
// does not depend on anyone reading an exit code.
Schedule::command('moderation:status')->hourly()->withoutOverlapping();

// Copies of refused content, kept only while the student can still appeal
// the decision. `excerpt_purge_after` has been stamped on every moderation
// row since the feature shipped and nothing ever acted on it, so the app
// was holding students' refused posts indefinitely.
//
// Daily rather than hourly: the window is measured in weeks, so the cost of
// being a few hours late is nil and the cost of scanning the table sixty
// times more often is not.
Schedule::command('moderation:purge-excerpts --force')->dailyAt('03:30')->withoutOverlapping();
