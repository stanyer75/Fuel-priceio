<?php

namespace App\Support;

/**
 * Small geometry helpers. Everything here works in [lat, lon] order.
 *
 * Seam: GeoJSON (openrouteservice) is [lon, lat]; Fuel Finder is
 * latitude/longitude fields. We convert at the API boundary and only ever
 * use [lat, lon] internally.
 */
final class Geo
{
    public const EARTH_RADIUS_M = 6371008.8;

    public const METRES_PER_MILE = 1609.344;

    public const LITRES_PER_UK_GALLON = 4.54609;

    /** Generous bounding box for the UK incl. Northern Ireland, Shetland, Scilly. */
    public const UK_BOUNDS = ['minLat' => 49.8, 'maxLat' => 60.95, 'minLon' => -8.7, 'maxLon' => 1.8];

    public static function inUk(float $lat, float $lon): bool
    {
        $b = self::UK_BOUNDS;

        return $lat >= $b['minLat'] && $lat <= $b['maxLat'] && $lon >= $b['minLon'] && $lon <= $b['maxLon'];
    }

    /** Great-circle distance in metres. */
    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    public static function milesToMetres(float $miles): float
    {
        return $miles * self::METRES_PER_MILE;
    }

    public static function metresToMiles(float $metres): float
    {
        return $metres / self::METRES_PER_MILE;
    }

    /**
     * Drop points closer than $minSpacingMetres to the previously kept point.
     * Always keeps the first and last point.
     *
     * @param  array<int, array{0: float, 1: float}>  $points
     * @return array<int, array{0: float, 1: float}>
     */
    public static function thin(array $points, float $minSpacingMetres): array
    {
        if (count($points) <= 2) {
            return array_values($points);
        }

        $kept = [$points[0]];
        $last = $points[0];
        $lastIndex = count($points) - 1;

        for ($i = 1; $i < $lastIndex; $i++) {
            if (self::distance($last[0], $last[1], $points[$i][0], $points[$i][1]) >= $minSpacingMetres) {
                $kept[] = $points[$i];
                $last = $points[$i];
            }
        }

        $kept[] = $points[$lastIndex];

        return $kept;
    }

    /**
     * Bounding box around a polyline, padded by $padMetres on every side.
     *
     * @param  array<int, array{0: float, 1: float}>  $points
     * @return array{minLat: float, maxLat: float, minLon: float, maxLon: float}
     */
    public static function boundingBox(array $points, float $padMetres): array
    {
        $lats = array_column($points, 0);
        $lons = array_column($points, 1);

        $padLat = rad2deg($padMetres / self::EARTH_RADIUS_M);
        // Longitude degrees shrink towards the poles; pad using the most northerly latitude.
        $padLon = $padLat / max(0.01, cos(deg2rad(max(array_map('abs', $lats)))));

        return [
            'minLat' => min($lats) - $padLat,
            'maxLat' => max($lats) + $padLat,
            'minLon' => min($lons) - $padLon,
            'maxLon' => max($lons) + $padLon,
        ];
    }

    /**
     * Shortest distance from a point to a polyline, and how far along the
     * polyline (from its start) that nearest point is.
     *
     * Uses an equirectangular projection centred on the point, which is
     * accurate to well under 1% over the few miles we care about.
     *
     * @param  array<int, array{0: float, 1: float}>  $line
     * @return array{distance: float, along: float}
     */
    public static function nearestOnPolyline(float $lat, float $lon, array $line): array
    {
        $cosLat = cos(deg2rad($lat));
        $project = fn (array $p): array => [
            deg2rad($p[1] - $lon) * $cosLat * self::EARTH_RADIUS_M,
            deg2rad($p[0] - $lat) * self::EARTH_RADIUS_M,
        ];

        $best = ['distance' => INF, 'along' => 0.0];
        $travelled = 0.0;
        $count = count($line);

        if ($count === 1) {
            return ['distance' => self::distance($lat, $lon, $line[0][0], $line[0][1]), 'along' => 0.0];
        }

        for ($i = 0; $i < $count - 1; $i++) {
            [$ax, $ay] = $project($line[$i]);
            [$bx, $by] = $project($line[$i + 1]);
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $segLength = sqrt($dx * $dx + $dy * $dy);

            // Point is the origin (0,0) in this projection.
            $t = $segLength > 0 ? max(0.0, min(1.0, (-$ax * $dx - $ay * $dy) / ($segLength ** 2))) : 0.0;
            $cx = $ax + $t * $dx;
            $cy = $ay + $t * $dy;
            $distance = sqrt($cx * $cx + $cy * $cy);

            if ($distance < $best['distance']) {
                $best = ['distance' => $distance, 'along' => $travelled + $t * $segLength];
            }

            $travelled += $segLength;
        }

        return $best;
    }
}
