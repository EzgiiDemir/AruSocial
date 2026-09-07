<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

// Real fix for "360 tours only open in an external browser tab on web":
// the tour host (360.arucad.edu.tr) sends X-Frame-Options, which blocks any
// <iframe> pointed directly at it — un-bypassable from the browser side.
// X-Frame-Options only governs framing of the TOP-LEVEL document a browser
// requests, so this fetches the tour server-side (no framing check there)
// and re-serves it from OUR origin without that header.
//
// First cut of this only proxied the top-level HTML and pointed a <base
// href> at the real external host — that made every sub-resource (scripts,
// images, the locale file) a genuine cross-origin request again, and
// 360.arucad.edu.tr sends no Access-Control-Allow-Origin, so the browser
// correctly blocked them all. The real fix is to mirror the upstream's own
// path structure under this route (not just the one document): a relative
// reference in the tour's HTML/JS then resolves back to THIS route again,
// which proxies that specific sub-resource too — every request the browser
// makes stays same-origin, so CORS never enters the picture. No auth: the
// content is public campus tour media, and an iframe `src` load can't carry
// a bearer token anyway.
class TourProxyController extends Controller
{
    // Only ever proxy ARUCAD's own tour host — this is a narrow embedding
    // fix, not a general-purpose fetch-any-url proxy (SSRF surface).
    private const UPSTREAM_HOST = 'https://360.arucad.edu.tr';

    public function show(Request $request, string $path): Response
    {
        // Route wildcards are URL-decoded by Laravel; guard against escaping
        // the intended upstream tree regardless.
        if (str_contains($path, '..') || str_contains($path, '://')) {
            return response('Invalid path.', 400);
        }

        $query = $request->getQueryString();
        $upstreamUrl = self::UPSTREAM_HOST.'/'.$path.($query ? '?'.$query : '');

        try {
            // Same host as the campus-directory integration, so it shares
            // its verify_ssl setting — local Windows PHP/cURL builds
            // otherwise fail the TLS handshake to this host even though a
            // plain `curl` from the same machine succeeds fine.
            $upstream = Http::timeout(15)
                ->withOptions(['verify' => (bool) config('services.campus_directory.verify_ssl', true)])
                ->get($upstreamUrl);
        } catch (\Throwable) {
            return response('Tour could not be reached.', 502);
        }

        if ($upstream->failed()) {
            return response('Tour could not be reached.', 502);
        }

        $contentType = $upstream->header('Content-Type') ?: 'application/octet-stream';
        $body = $upstream->body();

        if (str_contains($contentType, 'text/html')) {
            // A relative reference in this document (script/img/css/xhr)
            // must resolve back to this same route tree, not to the real
            // external origin — that is what keeps every follow-up request
            // same-origin. Absolute-path references (a bare leading "/",
            // uncommon in these portable exports but possible) still need
            // this prefix, which <base href> alone cannot fix; those would
            // need per-reference rewriting if ARUCAD's exports ever use
            // them. Deliberately no X-Frame-Options / CSP frame-ancestors
            // on our own response — that is the entire point of this route.
            $dir = str_contains($path, '/') ? substr($path, 0, strrpos($path, '/') + 1) : '';
            $baseHref = '/api/v1/tour-proxy/'.$dir;
            $baseTag = '<base href="'.htmlspecialchars($baseHref, ENT_QUOTES).'">';
            $body = stripos($body, '<head>') !== false
                ? preg_replace('/<head[^>]*>/i', '$0'.$baseTag, $body, 1)
                : $baseTag.$body;

            return response($body, 200)->header('Content-Type', 'text/html; charset=UTF-8');
        }

        return response($body, 200)->header('Content-Type', $contentType);
    }
}
