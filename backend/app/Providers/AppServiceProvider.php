<?php

namespace App\Providers;

use App\Services\FcmClient;
use App\Services\HttpFcmClient;
use App\Services\NullFcmClient;
use App\Support\EnvironmentGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FcmClient::class, function ($app) {
            $projectId = (string) config('services.fcm.project_id');
            $clientEmail = (string) config('services.fcm.client_email');
            $privateKey = (string) config('services.fcm.private_key');
            if ($projectId === '' || $clientEmail === '' || $privateKey === '') {
                return new NullFcmClient;
            }

            return new HttpFcmClient($projectId, $clientEmail, $privateKey);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        EnvironmentGuard::assertSafe();

        // The "api" named limiter used by `throttle:api` in routes/api.php
        // — Laravel 11+'s minimal skeleton doesn't pre-register this the
        // way older versions did, so it must be defined explicitly or the
        // whole route group 500s (see docs/EKSIKLER.md §1: real rate
        // limiting, now actually wired). 300 req/min per authenticated
        // user, falling back to per-IP for unauthenticated requests —
        // raised from an earlier 120 once the app grew enough real
        // subsystems (admin panel now loads several lists per tab, e.g.
        // the events form alone fetches places + academic years) that
        // 120 started tripping on genuine, non-abusive usage bursts.
        RateLimiter::for('api', function ($request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });
    }
}
