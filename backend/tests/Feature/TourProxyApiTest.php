<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
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

    /*
     * A 3DVista export is hundreds of immutable panorama tiles and skin
     * images. With no Cache-Control the browser re-requested every one of
     * them through PHP on each visit and each scene change, which is what
     * made a tour slow to open the second time as much as the first.
     */
    public function test_a_proxied_asset_is_cacheable_and_keeps_its_upstream_validators(): void
    {
        Http::fake([
            'https://360.arucad.edu.tr/vista_export/Main/media/panorama_0.jpg' => Http::response(
                'jpegbytes',
                200,
                ['Content-Type' => 'image/jpeg', 'ETag' => '"abc123"', 'Last-Modified' => 'Mon, 01 Sep 2025 10:00:00 GMT'],
            ),
        ]);

        $response = $this->get('/api/v1/tour-proxy/vista_export/Main/media/panorama_0.jpg');

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'max-age=86400, public');
        $response->assertHeader('ETag', '"abc123"');
        $response->assertHeader('Last-Modified', 'Mon, 01 Sep 2025 10:00:00 GMT');
    }

    public function test_a_revalidation_is_forwarded_upstream_and_answered_with_304(): void
    {
        Http::fake([
            'https://360.arucad.edu.tr/*' => Http::response('', 304, ['ETag' => '"abc123"']),
        ]);

        $response = $this
            ->withHeaders(['If-None-Match' => '"abc123"'])
            ->get('/api/v1/tour-proxy/vista_export/Main/media/panorama_0.jpg');

        $response->assertStatus(304);
        Http::assertSent(fn ($request) => $request->header('If-None-Match') === ['"abc123"']);
    }

    /*
     * The document we serve is not the document upstream sent — it carries
     * an injected <base href>. Passing upstream's validator on would let a
     * browser revalidate our rewritten copy against the original and be
     * told, wrongly, that nothing changed.
     */
    public function test_the_rewritten_html_document_does_not_reuse_the_upstream_validator(): void
    {
        Http::fake([
            'https://360.arucad.edu.tr/*' => Http::response(
                '<html><head></head><body/></html>',
                200,
                ['Content-Type' => 'text/html', 'ETag' => '"upstream-html"'],
            ),
        ]);

        $response = $this->get('/api/v1/tour-proxy/vista_export/Main/index.htm');

        $response->assertOk();
        $this->assertNull($response->headers->get('ETag'));
        $response->assertHeader('Cache-Control', 'max-age=300, public');
    }

    /*
     * A tour opening is hundreds of requests in one burst, and this route
     * is public so the general budget is keyed by IP — one student on
     * campus wifi would have throttled everyone behind the same NAT, and
     * the tour would have half-loaded with no visible error.
     */
    public function test_the_tour_proxy_is_not_on_the_general_api_rate_limit(): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v1/tour-proxy/{path}');

        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();
        $this->assertContains('throttle:tour-proxy', $middleware);
        $this->assertNotContains('throttle:api', $middleware);
    }

    public function test_a_failed_upstream_fetch_returns_a_clean_error_not_a_crash(): void
    {
        Http::fake([
            'https://360.arucad.edu.tr/*' => Http::response('', 500),
        ]);

        $this->get('/api/v1/tour-proxy/vista_export/Main/index.htm')->assertStatus(502);
    }
}
