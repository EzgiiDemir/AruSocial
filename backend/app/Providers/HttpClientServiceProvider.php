<?php

namespace App\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

/**
 * Gives every outbound HTTPS call a CA bundle to verify against.
 *
 * PHP on Windows ships without one, so `curl.cainfo` is empty and every
 * request fails with "unable to get local issuer certificate". The workaround
 * that had grown up around this was to switch TLS verification *off* per
 * host, which trades a configuration gap for a real security hole — the
 * moderation call carries an API key, and the tour proxy carries campus data.
 *
 * Pointing at a bundled cacert.pem fixes the cause instead: verification
 * stays on everywhere. If the bundle is absent (a normal Linux server with a
 * working system store) nothing is overridden and the system default applies.
 */
class HttpClientServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $bundle = $this->caBundlePath();
        if ($bundle === null) {
            return;
        }

        Http::globalOptions(['verify' => $bundle]);

        // Guzzle clients created outside the Http facade read these.
        if (ini_get('curl.cainfo') === '') {
            @ini_set('curl.cainfo', $bundle);
        }
        if (ini_get('openssl.cafile') === '') {
            @ini_set('openssl.cafile', $bundle);
        }
    }

    private function caBundlePath(): ?string
    {
        // An explicitly configured bundle always wins.
        $configured = env('CURL_CA_BUNDLE');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        // Only step in when PHP has no bundle of its own.
        if (ini_get('curl.cainfo') !== '' || ini_get('openssl.cafile') !== '') {
            return null;
        }

        $bundled = storage_path('certs/cacert.pem');

        return is_file($bundled) ? $bundled : null;
    }
}
