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
Route::get('/legal/terms', [LegalController::class, 'terms']);
