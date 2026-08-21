<?php

use App\Http\Controllers\ActivityFormController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Real, single-use activity form (docs/EKSIKLER.md aktivite/onay
// workflow §1/§2) — the actual link ActivityFormRequestedMail sends,
// verified by Laravel's own signed-URL HMAC (`->name()` + `signed`
// middleware) rather than a hand-rolled token. Deliberately outside
// routes/api.php: this is a browser-rendered HTML page a student opens
// from their email on any device, not a JSON endpoint the Flutter app
// calls.
Route::get('/activity-form/{event}', [ActivityFormController::class, 'show'])
    ->name('activity.form.show')->middleware('signed');
Route::post('/activity-form/{event}', [ActivityFormController::class, 'submit'])
    ->name('activity.form.submit')->middleware('signed');
