<?php

namespace App\Providers;

use App\Services\Ai\AskTrace;
use App\Services\Ai\Facts\SupportedFactsRollout;
use App\Services\Ai\RetrievalVersion;
use App\Services\FcmClient;
use App\Services\HttpFcmClient;
use App\Services\NullFcmClient;
use App\Services\Sis\SisProvider;
use App\Services\Sis\UnavailableSisProvider;
use App\Services\Web\BraveWebSearch;
use App\Services\Web\NullWebSearch;
use App\Services\Web\TavilyWebSearch;
use App\Services\Web\WebSearchProvider;
use App\Support\EnvironmentGuard;
use App\Support\RequestMemo;
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
        $this->app->singleton(SisProvider::class, UnavailableSisProvider::class);

        // One trace per request (or queued job): every Ask stage writes to
        // the same object, and the next request starts with an empty one.
        $this->app->scoped(AskTrace::class);
        $this->app->scoped(RequestMemo::class);
        $this->app->scoped(SupportedFactsRollout::class);

        /*
         * Web search resolves to a provider that does nothing unless a key is
         * present, exactly like FcmClient below.
         *
         * Bound here rather than checked at each call site so that "web
         * search is off" is the shape every test sees by default, instead of
         * a branch only an integration test reaches.
         */
        $this->app->singleton(WebSearchProvider::class, function () {
            if (trim((string) config('ai.web_research.key')) === '') {
                return new NullWebSearch;
            }

            // Tavily by default: it returns extracted page text with each
            // result, which removes the fetch-and-extract half of the
            // research path and the SSRF surface that comes with it.
            return (string) config('ai.web_research.provider', 'tavily') === 'brave'
                ? new BraveWebSearch
                : new TavilyWebSearch;
        });

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
        // Any edit to data the assistant reads invalidates cached answers.
        RetrievalVersion::listen();

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

        // Ask ARUCAD / Groq proxy — tighter than the general API budget so
        // a single client cannot burn the upstream key at 300 req/min.
        RateLimiter::for('ai', function ($request) {
            $perMinute = (int) config('services.groq.rate_limit_per_minute', 20);

            return Limit::perMinute(max(1, $perMinute))->by($request->user()?->id ?: $request->ip());
        });

        /*
         * Reports and appeals.
         *
         * Deliberately much tighter than the general budget, and keyed to
         * the account rather than the IP: report flooding buries real
         * cases under noise, and a shared campus network would otherwise
         * let one abuser throttle a whole building.
         *
         * Nobody files twenty genuine reports a minute. Someone doing so
         * is the behaviour this limit exists for.
         */
        RateLimiter::for('reports', function ($request) {
            $perHour = (int) config('moderation.reports.rate_limit_per_hour', 20);

            return Limit::perHour(max(1, $perHour))->by($request->user()?->id ?: $request->ip());
        });

        /*
         * Integration connection tests.
         *
         * Each press makes a real outbound request with a real credential,
         * so this is rate limited even though only staff can reach it: an
         * admin holding down "test" would otherwise hammer a provider from
         * our IP with our key and could get the key throttled or blocked at
         * their end. Keyed to the account, because the point is to bound
         * one operator's outbound traffic, not to punish a shared office IP.
         */
        RateLimiter::for('integration-tests', function ($request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        /*
         * Live map presence pings.
         *
         * The app sends one fix a minute while the map is open, so this is
         * generous for real use and still stops a client (or a script with
         * a stolen token) from writing presence hundreds of times a minute
         * to inflate a building's crowd count. Keyed to the account, since
         * one account only ever occupies one place.
         */
        RateLimiter::for('presence', function ($request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        /*
         * 360 tour proxy.
         *
         * Sized for what one tour actually costs: a 3DVista export pulls
         * hundreds of panorama tiles and skin images in a burst, so the
         * general 300/min budget throttled a single student mid-load, and
         * being a public route it is keyed by IP — so everyone behind the
         * same campus NAT shared that one budget. Still bounded, because
         * this reaches an upstream host on our behalf.
         */
        RateLimiter::for('tour-proxy', function ($request) {
            return Limit::perMinute(1200)->by($request->ip());
        });
    }
}
