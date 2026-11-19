<?php

namespace App\Services\Journey;

use App\Exceptions\UpstreamException;
use App\Services\Prices\StationSource;
use App\Services\Routing\OpenRouteServiceClient;
use App\Support\Geo;
use Illuminate\Support\Facades\Cache;

/**
 * Orchestrates one search: geocode -> route -> stations near route ->
 * matrix -> verdict.
 *
 * Request budget per (uncached) search:
 *   openrouteservice: 2 geocodes (cached 7d) + 1 route (cached 24h) + 1 matrix
 *   prices:           CheckFuelPrices: one request per search circle (each
 *                     cached 30 min); synced source: 0 (local database)
 */
class JourneyPlanner
{
    public function __construct(
        private readonly OpenRouteServiceClient $ors,
        private readonly StationSource $stations,
    ) {}

    public function plan(JourneyQuery $query): array
    {
        // Cache invalidation: the source supplies a version (for the synced
        // source, the id of the latest successful price sync), so new prices
        // make every cached search obsolete at once.
        $key = 'journey:'.$this->stations->cacheVersion().':'.$query->cacheKey();

        $cached = Cache::get($key);
        if (is_array($cached)) {
            $cached['meta']['from_cache'] = true;

            return $cached;
        }

        $result = $this->compute($query);
        Cache::put($key, $result, now()->addMinutes((int) config('fueldetour.journey.search_cache_minutes')));

        return $result;
    }

    private function compute(JourneyQuery $q): array
    {
        $config = config('fueldetour.journey');

        $from = $this->ors->geocode($q->from);
        $to = $this->ors->geocode($q->to);

        if (Geo::distance($from->lat, $from->lon, $to->lat, $to->lon) < 1000) {
            throw new UpstreamException('app', 'same_place',
                "\"{$from->label}\" and \"{$to->label}\" are less than 1 km apart - nothing to plan.", 422);
        }

        $route = $this->ors->route($from, $to);
        $routeMiles = Geo::metresToMiles($route['distance_m']);

        if ($routeMiles > $config['max_route_miles']) {
            throw new UpstreamException('app', 'route_too_long', sprintf(
                'That route is %d miles; this demo searches journeys up to %d miles.', $routeMiles, $config['max_route_miles']), 422);
        }

        $found = $this->stations->find($route['geometry'], $q->fuel, $q->corridorMiles);
        $excluded = $found['excluded'] + ['unroutable' => 0, 'not_checked_matrix_limit' => 0];

        [$selected, $excluded['not_checked_matrix_limit']] = $this->selectForMatrix($found['candidates']);

        $stations = [];
        if ($selected !== []) {
            [$stations, $excluded['unroutable']] = $this->measureDetours($from, $to, $selected);
        }

        $calculator = new DetourCalculator($q->mpg, $q->litres);
        [$stations, $recommendation] = $this->rank($stations, $calculator, (float) $config['min_worthwhile_saving_gbp']);

        return [
            'from' => $from->toArray(),
            'to' => $to->toArray(),
            'route' => [
                'distance_miles' => round($routeMiles, 1),
                'duration_minutes' => (int) round($route['duration_s'] / 60),
                'geometry' => array_map(fn ($p) => [round($p[0], 5), round($p[1], 5)], Geo::thin($route['geometry'], 500)),
            ],
            'fuel' => ['code' => $q->fuel->value, 'label' => $q->fuel->label()],
            'assumptions' => [
                'mpg' => $q->mpg,
                'litres' => $q->litres,
                'corridor_miles' => $q->corridorMiles,
                'min_worthwhile_saving_gbp' => (float) $config['min_worthwhile_saving_gbp'],
                'notes' => [
                    'Detour = extra road distance vs. driving straight from start to destination.',
                    'Detour fuel is costed at that station\'s price; time is not given a cash value.',
                    'MPG is UK (imperial): 4.54609 litres per gallon.',
                ],
            ],
            'recommendation' => $recommendation,
            'stations' => $stations,
            'excluded' => $excluded,
            'meta' => $this->meta($found['meta']),
        ];
    }

    /**
     * The ORS matrix is the expensive call, so only send the most useful
     * candidates: the few stations closest to the route (to find the
     * "convenient" baseline) plus the cheapest ones (likely winners).
     *
     * @return array{0: list<array>, 1: int} [selected, number left out]
     */
    private function selectForMatrix(array $candidates): array
    {
        $max = (int) config('fueldetour.routing.matrix_max_candidates');
        $nearestCount = (int) config('fueldetour.journey.nearest_candidates');

        $byDistance = $candidates;
        usort($byDistance, fn ($a, $b) => $a['distance_from_route_m'] <=> $b['distance_from_route_m']);
        $byPrice = $candidates;
        usort($byPrice, fn ($a, $b) => [$a['price_ppl'], $a['distance_from_route_m']] <=> [$b['price_ppl'], $b['distance_from_route_m']]);

        $selected = [];
        foreach (array_slice($byDistance, 0, $nearestCount) as $c) {
            $selected[$c['node_id']] = $c;
        }
        foreach ($byPrice as $c) {
            if (count($selected) >= $max) {
                break;
            }
            $selected[$c['node_id']] ??= $c;
        }

        return [array_values($selected), count($candidates) - count($selected)];
    }

    /**
     * One matrix call gives every detour:
     *   locations    = [start, end, s1..sn]
     *   sources      = [start, s1..sn]   (row 0 = start, row k = station k)
     *   destinations = [end,   s1..sn]   (col 0 = end,   col k = station k)
     *   detour(k)    = start->sk + sk->end - start->end
     *
     * @return array{0: list<array>, 1: int} [stations with detours, unroutable count]
     */
    private function measureDetours($from, $to, array $selected): array
    {
        $n = count($selected);
        $locations = array_merge(
            [[$from->lat, $from->lon], [$to->lat, $to->lon]],
            array_map(fn ($s) => [$s['lat'], $s['lon']], $selected),
        );
        $stationIndexes = range(2, $n + 1);

        $matrix = $this->ors->matrix($locations, array_merge([0], $stationIndexes), array_merge([1], $stationIndexes));
        $dist = $matrix['distances'];
        $time = $matrix['durations'];

        $direct = $dist[0][0] ?? null;
        $directTime = $time[0][0] ?? null;
        if ($direct === null) {
            throw new UpstreamException('openrouteservice', 'no_route', 'The routing matrix could not connect start and destination.', 422);
        }

        $out = [];
        $unroutable = 0;

        foreach ($selected as $i => $station) {
            $k = $i + 1;
            $there = $dist[0][$k] ?? null;   // start -> station
            $onward = $dist[$k][0] ?? null;  // station -> end

            // Seam: matrix cells are null when ORS can't snap a station to a
            // road (e.g. bad coordinates, private forecourt roads).
            if ($there === null || $onward === null) {
                $unroutable++;

                continue;
            }

            // Clamp: small negatives happen because route snapping differs per pair.
            $detourM = max(0.0, $there + $onward - $direct);
            $detourS = max(0.0, ($time[0][$k] ?? 0) + ($time[$k][0] ?? 0) - $directTime);

            $out[] = $station + [
                'detour_m' => $detourM,
                'detour_s' => $detourS,
            ];
        }

        return [$out, $unroutable];
    }

    /** @return array{0: list<array>, 1: array} [ranked stations for output, recommendation] */
    private function rank(array $stations, DetourCalculator $calc, float $threshold): array
    {
        if ($stations === []) {
            return [[], [
                'verdict' => 'no_stations',
                'message' => 'No stations selling this fuel were found near the route. Try widening the search corridor.',
                'node_id' => null,
            ]];
        }

        // "Convenient" baseline = least detour (ties broken by price).
        $convenient = $stations;
        usort($convenient, fn ($a, $b) => [round($a['detour_m'], -1), $a['price_ppl']] <=> [round($b['detour_m'], -1), $b['price_ppl']]);
        $convenient = $convenient[0];

        $baselineCost = $calc->totalCost($convenient['price_ppl'], Geo::metresToMiles($convenient['detour_m']));
        $oldAfterHours = (int) config('fueldetour.journey.price_old_after_hours');

        $rows = array_map(function ($s) use ($calc, $baselineCost, $convenient, $oldAfterHours) {
            $detourMiles = Geo::metresToMiles($s['detour_m']);
            $total = $calc->totalCost($s['price_ppl'], $detourMiles);
            $ageHours = $s['price_updated_at'] ? $s['price_updated_at']->diffInMinutes(now()) / 60 : null;

            return [
                'node_id' => $s['node_id'],
                'name' => $s['name'],
                'brand' => $s['brand'],
                'address' => $s['address'],
                'postcode' => $s['postcode'],
                'lat' => $s['lat'],
                'lon' => $s['lon'],
                'is_motorway_services' => $s['is_motorway_services'],
                'is_supermarket' => $s['is_supermarket'],
                'price_ppl' => $s['price_ppl'],
                'price_updated_at' => $s['price_updated_at']?->toIso8601String(),
                'price_age_hours' => $ageHours === null ? null : round($ageHours, 1),
                'price_is_old' => $ageHours === null || $ageHours > $oldAfterHours,
                'miles_from_route' => round(Geo::metresToMiles($s['distance_from_route_m']), 2),
                'miles_into_journey' => round(Geo::metresToMiles($s['along_route_m']), 1),
                'detour_miles' => round($detourMiles, 2),
                'detour_minutes' => (int) round($s['detour_s'] / 60),
                'fill_cost_gbp' => round($calc->fillCost($s['price_ppl']), 2),
                'detour_fuel_cost_gbp' => round($calc->detourCost($detourMiles, $s['price_ppl']), 2),
                'total_cost_gbp' => round($total, 2),
                'saving_vs_convenient_gbp' => round($baselineCost - $total, 2),
                'is_convenient' => $s['node_id'] === $convenient['node_id'],
                'data_corrections' => $s['data_corrections'],
            ];
        }, $stations);

        usort($rows, fn ($a, $b) => [$a['total_cost_gbp'], $a['detour_miles']] <=> [$b['total_cost_gbp'], $b['detour_miles']]);
        $best = $rows[0];

        $recommendation = match (true) {
            $best['is_convenient'] => [
                'verdict' => 'stay_on_route',
                'node_id' => $best['node_id'],
                'message' => sprintf('Best value is also the most convenient: %s at %.1fp/litre (%s).',
                    $best['name'], $best['price_ppl'], $this->detourText($best)),
            ],
            $best['saving_vs_convenient_gbp'] >= $threshold => [
                'verdict' => 'detour_worth_it',
                'node_id' => $best['node_id'],
                'message' => sprintf('Worth the detour: %s at %.1fp/litre saves £%.2f overall vs. %s, even after the extra fuel (%s).',
                    $best['name'], $best['price_ppl'], $best['saving_vs_convenient_gbp'], $convenient['name'], $this->detourText($best)),
            ],
            default => [
                'verdict' => 'not_worth_it',
                'node_id' => $convenient['node_id'],
                'message' => sprintf('Not worth a detour: the cheapest option (%s) only saves £%.2f once extra fuel is counted. Use %s at %.1fp/litre.',
                    $best['name'], $best['saving_vs_convenient_gbp'], $convenient['name'], $convenient['price_ppl']),
            ],
        };

        return [$rows, $recommendation];
    }

    private function detourText(array $row): string
    {
        return $row['detour_miles'] < 0.1
            ? 'no detour'
            : sprintf('+%.1f miles, about +%d min', $row['detour_miles'], $row['detour_minutes']);
    }

    /** Source-specific details (attribution, coverage, warnings) plus our own. */
    private function meta(array $source): array
    {
        return [
            'data_source' => $source['source'],
            'attribution' => $source['attribution'] ?? null,
            'warnings' => $source['warnings'] ?? [],
            'coverage' => $source['coverage'] ?? null,
            'prices_synced_at' => $source['prices_synced_at'] ?? null,
            'ors_quota_remaining' => $this->ors->quotaRemaining,
            'generated_at' => now()->toIso8601String(),
            'from_cache' => false,
        ];
    }
}
