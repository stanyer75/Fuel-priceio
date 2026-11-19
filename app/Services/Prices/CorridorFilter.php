<?php

namespace App\Services\Prices;

use App\Support\Geo;
use Carbon\CarbonImmutable;

/**
 * The rules every price source shares, applied to normalised stations:
 *  - within N miles (straight line) of the route - a SHORTLIST only; real
 *    detours come from the routing matrix;
 *  - not temporarily closed;
 *  - price not older than the configured maximum age.
 *
 * Normalised station shape:
 *   node_id, name, brand, address, postcode, lat, lon, is_motorway_services,
 *   is_supermarket, temporary_closure, price_ppl,
 *   price_updated_at (?CarbonImmutable), data_corrections (list<string>)
 */
class CorridorFilter
{
    /**
     * @param  iterable<array>  $stations
     * @param  list<array{0: float, 1: float}>  $line  route, already thinned
     * @return array{candidates: list<array>, excluded: array<string, int>}
     */
    public function apply(iterable $stations, array $line, float $corridorMiles): array
    {
        $corridorMetres = Geo::milesToMetres($corridorMiles);
        $oldest = CarbonImmutable::now()->subDays((int) config('fueldetour.journey.price_max_age_days'));
        $excluded = ['temporarily_closed' => 0, 'price_too_old' => 0];
        $candidates = [];

        foreach ($stations as $station) {
            $nearest = Geo::nearestOnPolyline($station['lat'], $station['lon'], $line);
            if ($nearest['distance'] > $corridorMetres) {
                continue;
            }

            if ($station['temporary_closure']) {
                $excluded['temporarily_closed']++;

                continue;
            }

            if ($station['price_updated_at'] && $station['price_updated_at']->lt($oldest)) {
                $excluded['price_too_old']++;

                continue;
            }

            $candidates[] = $station + [
                'distance_from_route_m' => $nearest['distance'],
                'along_route_m' => $nearest['along'],
            ];
        }

        return ['candidates' => $candidates, 'excluded' => $excluded];
    }
}
