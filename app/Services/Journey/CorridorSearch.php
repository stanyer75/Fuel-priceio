<?php

namespace App\Services\Journey;

use App\Enums\FuelType;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Finds stations selling a fuel within N miles (straight line) of a route.
 *
 * Seam: the route is a polyline from openrouteservice; stations are points
 * from Fuel Finder; neither API can answer "which stations are near this
 * line?". We do it ourselves in two steps:
 *   1. SQL bounding-box prefilter on our synced copy (cheap, indexed);
 *   2. exact point-to-polyline distance in PHP.
 *
 * Straight-line distance is only a CANDIDATE filter: a station 200 m away
 * may be across a dual carriageway. Real detours come from the ORS matrix.
 */
class CorridorSearch
{
    /**
     * @param  list<array{0: float, 1: float}>  $route  [lat, lon]
     * @return array{candidates: list<array>, excluded: array<string, int>}
     */
    public function find(array $route, FuelType $fuel, float $corridorMiles): array
    {
        $corridorMetres = Geo::milesToMetres($corridorMiles);
        $line = Geo::thin($route, 200);
        $box = Geo::boundingBox($line, $corridorMetres);

        $rows = DB::table('stations as s')
            ->join('station_prices as p', 'p.node_id', '=', 's.node_id')
            ->where('p.fuel_type', $fuel->value)
            ->where('s.permanent_closure', false)
            ->whereBetween('s.latitude', [$box['minLat'], $box['maxLat']])
            ->whereBetween('s.longitude', [$box['minLon'], $box['maxLon']])
            ->select([
                's.node_id', 's.trading_name', 's.brand_name', 's.address_line_1', 's.city', 's.postcode',
                's.latitude', 's.longitude', 's.is_motorway_service_station', 's.is_supermarket_service_station',
                's.temporary_closure', 's.data_corrections as station_corrections',
                'p.price_ppl', 'p.price_updated_at', 'p.data_corrections as price_corrections',
            ])
            ->get();

        $maxAgeDays = (int) config('fueldetour.journey.price_max_age_days');
        $excluded = ['temporarily_closed' => 0, 'price_too_old' => 0];
        $candidates = [];

        foreach ($rows as $row) {
            $nearest = Geo::nearestOnPolyline((float) $row->latitude, (float) $row->longitude, $line);
            if ($nearest['distance'] > $corridorMetres) {
                continue;
            }

            if ($row->temporary_closure) {
                $excluded['temporarily_closed']++;

                continue;
            }

            $updatedAt = $row->price_updated_at ? CarbonImmutable::parse($row->price_updated_at, 'UTC') : null;
            if ($updatedAt && $updatedAt->lt(now()->subDays($maxAgeDays))) {
                $excluded['price_too_old']++;

                continue;
            }

            $candidates[] = [
                'node_id' => $row->node_id,
                'name' => $row->trading_name,
                'brand' => $row->brand_name,
                'address' => trim(implode(', ', array_filter([$row->address_line_1, $row->city]))),
                'postcode' => $row->postcode,
                'lat' => (float) $row->latitude,
                'lon' => (float) $row->longitude,
                'is_motorway_services' => (bool) $row->is_motorway_service_station,
                'is_supermarket' => (bool) $row->is_supermarket_service_station,
                'price_ppl' => (float) $row->price_ppl,
                'price_updated_at' => $updatedAt,
                'distance_from_route_m' => $nearest['distance'],
                'along_route_m' => $nearest['along'],
                'data_corrections' => array_values(array_unique(array_merge(
                    json_decode($row->station_corrections ?? '[]', true) ?: [],
                    json_decode($row->price_corrections ?? '[]', true) ?: [],
                ))),
            ];
        }

        return ['candidates' => $candidates, 'excluded' => $excluded];
    }
}
