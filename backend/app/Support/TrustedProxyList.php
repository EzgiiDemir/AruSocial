<?php

namespace App\Support;

// Parses TRUSTED_PROXIES (P3-7 §13) into whatever
// Middleware::trustProxies(at: ...) expects. Kept out of bootstrap/app.php
// so the actual parsing rules are unit-testable without booting the app.
class TrustedProxyList
{
    /**
     * @return array<int, string>|string|null null = trust no proxy (the
     *                                        safe default; every
     *                                        X-Forwarded-* header is
     *                                        ignored). '*' = trust
     *                                        whichever host actually
     *                                        connected — only sane when
     *                                        Laravel is never reachable
     *                                        except through that proxy.
     *                                        array = an explicit
     *                                        allowlist of proxy IPs/CIDRs.
     */
    public static function parse(?string $raw): array|string|null
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        if ($raw === '*') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $ip) => $ip !== ''));
    }
}
