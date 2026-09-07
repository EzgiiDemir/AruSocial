<?php

namespace App\Support;

/**
 * Server-side campus envelope. Client GPS is advisory; check-in is
 * rejected unless the point is inside a campus polygon (default) or,
 * if CHECKIN_GEOFENCE_MODE=radius, within CAMPUS_GEOFENCE_METERS of a
 * real ARUCAD site (same three coordinates as Flutter campus_sites.dart).
 *
 * Polygons are axis-aligned envelopes around each site — not a cadastral
 * survey. Replace half-extents when ARUCAD provides official GeoJSON.
 */
class CampusGeofence
{
    /** @var list<array{id: string, lat: float, lng: float}> */
    public const SITES = [
        ['id' => 'main', 'lat' => 35.337395, 'lng' => 33.321358],
        ['id' => 'bandabuliya', 'lat' => 35.175513, 'lng' => 33.365029],
        ['id' => 'atelier', 'lat' => 35.333593, 'lng' => 33.330680],
    ];

    /** ~610 m north/south of each site centre. */
    public const HALF_LAT = 0.0055;

    /** ~590 m east/west of each site centre. */
    public const HALF_LNG = 0.0065;

    public static function mode(): string
    {
        $mode = strtolower((string) config('services.checkin.geofence_mode', 'polygon'));

        return $mode === 'radius' ? 'radius' : 'polygon';
    }

    public static function radiusMeters(): float
    {
        return (float) config('services.checkin.campus_radius_meters', 3000);
    }

    public static function contains(float $lat, float $lng): bool
    {
        if (self::mode() === 'radius') {
            $allowed = self::radiusMeters();
            foreach (self::SITES as $site) {
                if (self::distanceMeters($lat, $lng, $site['lat'], $site['lng']) <= $allowed) {
                    return true;
                }
            }

            return false;
        }

        foreach (self::polygons() as $ring) {
            if (self::pointInRing($lat, $lng, $ring)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<list<array{0: float, 1: float}>>
     */
    public static function polygons(): array
    {
        $out = [];
        foreach (self::SITES as $site) {
            $out[] = self::box((float) $site['lat'], (float) $site['lng']);
        }

        return $out;
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    public static function box(float $lat, float $lng, ?float $dLat = null, ?float $dLng = null): array
    {
        $dLat ??= self::HALF_LAT;
        $dLng ??= self::HALF_LNG;

        return [
            [$lat + $dLat, $lng - $dLng],
            [$lat + $dLat, $lng + $dLng],
            [$lat - $dLat, $lng + $dLng],
            [$lat - $dLat, $lng - $dLng],
        ];
    }

    /**
     * Ray-casting. Ring vertices are [lat, lng].
     *
     * @param  list<array{0: float, 1: float}>  $ring
     */
    public static function pointInRing(float $lat, float $lng, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        if ($n < 3) {
            return false;
        }
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $yi = $ring[$i][0];
            $xi = $ring[$i][1];
            $yj = $ring[$j][0];
            $xj = $ring[$j][1];
            $denom = ($yj - $yi) ?: 1e-12;
            $intersect = (($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / $denom + $xi);
            if ($intersect) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000.0;
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLam = deg2rad($lng2 - $lng1);
        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLam / 2) ** 2;

        return 2 * $earth * asin(min(1.0, sqrt($a)));
    }
}
