<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\TestCase;

// P3-7 §12. Illuminate\Http\Middleware\TrustHosts derives its trusted
// pattern straight from config('app.url') — the same public origin
// EnvironmentGuard already forces staging/production to set — so there is
// nothing app-specific to configure beyond turning it on.
//
// TrustHosts::shouldSpecifyTrustedHosts() hard-codes a skip whenever
// runningUnitTests() is true, which is always the case here — so an actual
// end-to-end HTTP request can't exercise the *enforcement* in this suite.
// These tests instead pin down the two things that matter: (1) the
// middleware is really registered (bootstrap/app.php's trustHosts() call
// took effect), and (2) the pattern it derives from APP_URL matches the
// intended host and nothing else.
class HostTest extends TestCase
{
    public function test_trust_hosts_middleware_is_registered_in_the_global_stack(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $property = new \ReflectionProperty($kernel, 'middleware');
        $property->setAccessible(true);

        $this->assertContains(TrustHosts::class, $property->getValue($kernel));
    }

    public function test_trust_proxies_middleware_is_registered_in_the_global_stack(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $property = new \ReflectionProperty($kernel, 'middleware');
        $property->setAccessible(true);

        $this->assertContains(TrustProxies::class, $property->getValue($kernel));
    }

    public function test_the_approved_production_host_is_trusted(): void
    {
        config(['app.url' => 'https://production-api.example.com']);
        $middleware = $this->app->make(TrustHosts::class);

        $pattern = $this->onlyPattern($middleware->hosts());

        $this->assertMatchesRegularExpression($pattern, 'production-api.example.com');
    }

    public function test_a_subdomain_of_the_approved_host_is_trusted(): void
    {
        config(['app.url' => 'https://production-api.example.com']);
        $middleware = $this->app->make(TrustHosts::class);

        $pattern = $this->onlyPattern($middleware->hosts());

        $this->assertMatchesRegularExpression($pattern, 'admin.production-api.example.com');
    }

    public function test_an_unexpected_host_is_not_trusted(): void
    {
        config(['app.url' => 'https://production-api.example.com']);
        $middleware = $this->app->make(TrustHosts::class);

        $pattern = $this->onlyPattern($middleware->hosts());

        $this->assertDoesNotMatchRegularExpression($pattern, 'evil.example.com');
        $this->assertDoesNotMatchRegularExpression($pattern, 'production-api.example.com.evil.com');
    }

    /**
     * @param  array<int, string|null>  $hosts
     */
    private function onlyPattern(array $hosts): string
    {
        $hosts = array_values(array_filter($hosts));
        $this->assertNotEmpty($hosts, 'TrustHosts derived no pattern from app.url');

        return '#'.$hosts[0].'#i';
    }
}
