<?php

namespace App\Services\Prices;

use App\Support\Geo;
use Carbon\CarbonImmutable;

/**
 * The rules every price source shares, applied to normalised stations:
 *  - within N miles (straight line) of the route - a SHORTLIST only; real
 *    detours come from the routing matrix;
 *  - not temporarily closed;
 *  - price not a leftover from before the statutory scheme (CMA closure);
 *  - price not unchanged for longer than the maximum age.
 *
 * price_updated_at is when the price last CHANGED, so "old" mostly means
 * "stable"; see the price_* settings in config/fueldetour.php.
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
        $notBefore = CarbonImmutable::parse(config('fueldetour.journey.price_not_before'), 'UTC');
        $excluded = ['temporarily_closed' => 0, 'price_from_closed_scheme' => 0, 'price_unchanged_too_long' => 0];
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

            $changedAt = $station['price_updated_at'];

            // Abandoned: last touched under the old CMA scheme, e.g. 29/04/2026.
            if ($changedAt && $changedAt->lt($notBefore)) {
                $excluded['price_from_closed_scheme']++;

                continue;
            }

            // Plausible but implausibly long without a single change.
            if ($changedAt && $changedAt->lt($oldest)) {
                $excluded['price_unchanged_too_long']++;

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
