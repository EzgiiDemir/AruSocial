<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Real fix for "360 tours only open in an external browser tab on web":
 * 360.arucad.edu.tr sends X-Frame-Options, which blocks a direct <iframe>.
 * This proxy mirrors the upstream's own path structure under our origin so
 * every relative sub-resource reference (scripts/images/XHR) also resolves
 * back through the proxy — the first version only proxied the top document
 * and pointed a <base href> at the real host, which made every sub-resource
 * a real cross-origin request that the upstream's missing CORS headers
 * then blocked.
 */
class TourProxyApiTest extends TestCase
{
    public function test_it_proxies_html_and_rewrites_the_base_href_to_stay_same_origin(): void
    {
        Http::fake([
            'https://360.arucad.edu.tr/vista_export/Main/index.htm' => Http::response(
                '<html><head><title>Tour</title></head><body>tour</body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8', 'X-Frame-Options' => 'SAMEORIGIN'],
            ),
        ]);

        $response = $this->getJson('/api/v1/tour-proxy/vista_export/Main/index.htm');

        $response->assertOk();
        $this->assertStringContainsString(
            '<base href="/api/v1/tour-proxy/vista_export/Main/">',
            $response->getContent(),
        );
        // The whole point: our response never carries the upstream's
        // framing header, however the upstream mock set it.
        $this->assertNull($response->headers->get('X-Frame-Options'));
    }

    public function test_it_proxies_a_nested_sub_resource_with_its_own_content_type(): void
    {
        Http::fake([
            'https://360.arucad.edu.tr/vista_export/Main/lib/tdvgs.js' => Http::response(
                'console.log(1);',
                200,
                ['Content-Type' => 'application/javascript'],
            ),
        ]);

        $response = $this->get('/api/v1/tour-proxy/vista_export/Main/lib/tdvgs.js');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript');
        $this->assertSame('console.log(1);', $response->getContent());
    }

    public function test_it_forwards_the_query_string_to_the_upstream_request(): void
    {
        Http::fake([
            'https://360.arucad.edu.tr/vista_export/Main/locale/en.txt?v=123' => Http::response(
                'hello=world', 200, ['Content-Type' => 'text/plain'],
            ),
        ]);

        $this->get('/api/v1/tour-proxy/vista_export/Main/locale/en.txt?v=123')->assertOk();
        Http::assertSent(fn ($request) => $request->url() === 'https://360.arucad.edu.tr/vista_export/Main/locale/en.txt?v=123');
    }

    public function test_it_rejects_a_path_traversal_attempt(): void
    {
        Http::fake();

        $response = $this->get('/api/v1/tour-proxy/vista_export/../../etc/passwd');

        $response->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_it_does_not_require_authentication(): void
    {
        Http::fake([
            'https://360.arucad.edu.tr/*' => Http::response('<html><head></head><body/></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        // No actingAsUser()/withToken() — an iframe `src` load can't carry
        // a bearer token, so this must work unauthenticated.
        $this->get('/api/v1/tour-proxy/vista_export/Main/index.htm')->assertOk();
    }

    public function test_a_failed_upstream_fetch_returns_a_clean_error_not_a_crash(): void
    {
        Http::fake([
            'https://360.arucad.edu.tr/*' => Http::response('', 500),
        ]);

        $this->get('/api/v1/tour-proxy/vista_export/Main/index.htm')->assertStatus(502);
    }
}
