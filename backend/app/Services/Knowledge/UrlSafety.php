<?php

namespace App\Services\Knowledge;

/**
 * SSRF guard for the crawler.
 *
 * The domain allow-list is the primary defence — the crawler only ever
 * fetches the handful of public ARUCAD hosts. This adds defence in depth: even
 * an allow-listed host must not resolve to a private, loopback, link-local or
 * otherwise reserved address (which would be DNS-rebinding an internal service
 * behind a public name). A host that resolves only to such addresses is
 * refused before any request is made.
 */
class UrlSafety
{
    /** Reserved / private ranges an outbound crawler must never reach. */
    public static function isPublicHost(string $host): bool
    {
        $host = trim($host);
        if ($host === '') {
            return false;
        }

        // Literal loopback names, before any DNS lookup.
        if (in_array(strtolower($host), ['localhost', 'localhost.localdomain', 'ip6-localhost'], true)) {
            return false;
        }

        // If the host is already an IP literal, check it directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host);
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
        $ips = [];
        foreach ($records as $r) {
            if (isset($r['ip'])) {
                $ips[] = $r['ip'];
            }
            if (isset($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if ($ips === []) {
            // Fall back to a single A lookup; if that also fails, refuse.
            $resolved = gethostbyname($host);
            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
                $ips[] = $resolved;
            }
        }
        if ($ips === []) {
            return false;
        }

        // Every resolved address must be public — one private answer is enough
        // to reject the host.
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    public static function isPublicIp(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
    }
}
