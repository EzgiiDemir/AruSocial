<?php

use App\Http\Controllers\Web\DetailFormController;
use App\Http\Controllers\Web\LegalController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The real, working page behind every application email's "detaylı formu
// aç" link (see ParticipationApplicationService::detailFormUrl). Token-
// gated, not `/api` — a student may open this from any device without
// being signed into the app.
Route::get('/forms/application/{token}', [DetailFormController::class, 'show']);
Route::post('/forms/application/{token}', [DetailFormController::class, 'submit']);

Route::get('/legal/privacy', [LegalController::class, 'privacy']);
// The other half of what the consent checkbox covers, so a link in
// the app or an app store listing resolves to a real page.
Route::get('/legal/community-guidelines', [LegalController::class, 'guidelines']);
Route::get('/legal/terms', [LegalController::class, 'terms']);
// Public and unauthenticated on purpose: someone reporting abuse, or
// appealing a suspension, may not be able to sign in.
Route::get('/legal/safety', [LegalController::class, 'safety']);
