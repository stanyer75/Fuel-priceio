<?php

namespace App\Services\CheckFuelPrices;

use App\Enums\FuelType;
use App\Exceptions\UpstreamException;
use App\Services\Prices\CorridorFilter;
use App\Services\Prices\StationSource;
use App\Support\Geo;

/**
 * Live prices along a route from CheckFuelPrices' radius search.
 *
 * Seam: the routing API gives us a LINE; the price API only answers
 * "stations within R miles of a POINT", capped at 20 results sorted by
 * distance. So we cover the route corridor with overlapping circles:
 *
 *   - Split the route into windows of length L. A circle centred on the
 *     window midpoint with radius r = sqrt((L/2)^2 + c^2) covers the whole
 *     corridor (half-width c) for that window.
 *   - Start with r = 2c (L = 2*sqrt(3)*c, about 6.9 miles for c = 2 miles).
 *   - If a circle returns the full 20 results it was TRUNCATED (a town or
 *     city): cheaper stations further out are invisible. Split the window
 *     in half and query again, down to a minimum window length.
 *   - Merge results, de-duplicate by station id, then apply the shared
 *     corridor rules.
 *
 * Partial failure is tolerated: if some circles fail, the search still
 * returns what it found plus a warning saying how much was missed.
 */
class CheckFuelPricesStationSource implements StationSource
{
    public function __construct(
        private readonly CheckFuelPricesClient $client,
        private readonly CheckFuelPricesNormaliser $normaliser,
        private readonly CorridorFilter $filter,
        private readonly array $config,
    ) {}

    public function cacheVersion(): string
    {
        return 'cfp';
    }

    public function find(array $route, FuelType $fuel, float $corridorMiles): array
    {
        $code = CheckFuelPricesNormaliser::fuelCode($fuel);
        $line = Geo::thin($route, 200);
        $cumulative = $this->cumulative($line);
        $total = end($cumulative);

        $c = Geo::milesToMetres($corridorMiles);
        $initialWindow = 2 * sqrt(3) * $c;
        $minWindow = Geo::milesToMetres((float) $this->config['min_window_miles']);

        // Queue of [start, end] windows (metres along the route), in route order.
        $queue = [];
        for ($start = 0.0; $start < $total; $start += $initialWindow) {
            $queue[] = [$start, min($total, $start + $initialWindow)];
        }

        $stats = ['circles' => 0, 'requests_before' => $this->client->requests, 'cache_hits_before' => $this->client->cacheHits,
            'split' => 0, 'truncated_unresolved' => 0, 'failed' => 0, 'not_fetched' => 0];
        $budget = (int) $this->config['max_requests_per_search'];
        $stations = [];
        $rejections = [];
        $lastError = null;

        while ($queue) {
            [$start, $end] = array_shift($queue);

            if ($this->client->requests - $stats['requests_before'] >= $budget) {
                $stats['not_fetched'] += 1 + count($queue);
                break;
            }

            $half = ($end - $start) / 2;
            [$lat, $lon] = $this->pointAt($line, $cumulative, $start + $half);
            $radiusMiles = $this->clampRadius(Geo::metresToMiles(sqrt($half ** 2 + $c ** 2) * 1.05));

            try {
                $result = $this->client->stationsNear($lat, $lon, $radiusMiles, $code);
                $stats['circles']++;
            } catch (CheckFuelPricesException $e) {
                $stats['failed']++;
                $lastError = $e;
                // Rate limited (by them or by us): stop now rather than hammer.
                if (in_array($e->errorCode, ['rate_limited', 'local_rate_cap'], true)) {
                    $stats['not_fetched'] += count($queue);
                    break;
                }

                continue;
            }

            foreach ($result['stations'] as $raw) {
                $record = $this->normaliser->station(is_array($raw) ? $raw : [], $fuel);
                if (! $record->accepted()) {
                    $rejections[$record->rejection] = ($rejections[$record->rejection] ?? 0) + 1;

                    continue;
                }
                $stations[$record->row['node_id']] = $record->row; // overlapping circles: de-duplicate
            }

            if ($result['truncated']) {
                if ($end - $start >= 2 * $minWindow) {
                    // Search both halves with smaller circles, before continuing along the route.
                    array_unshift($queue, [$start, $start + $half], [$start + $half, $end]);
                    $stats['split']++;
                } else {
                    $stats['truncated_unresolved']++;
                }
            }
        }

        if ($stats['circles'] === 0 && $lastError) {
            throw $lastError; // nothing at all came back: surface the real cause
        }

        $found = $this->filter->apply(array_values($stations), $line, $corridorMiles);

        return [
            'candidates' => $found['candidates'],
            'excluded' => $found['excluded'] + $rejections,
            'meta' => $this->meta($stats, $lastError),
        ];
    }

    private function meta(array $stats, ?UpstreamException $lastError): array
    {
        $warnings = [];

        if ($stats['failed'] || $stats['not_fetched']) {
            $warnings[] = sprintf(
                'Partial coverage: %d part(s) of the route could not be searched%s. Prices there are missing.',
                $stats['failed'] + $stats['not_fetched'],
                $lastError ? " ({$lastError->errorCode})" : ' (request budget reached)',
            );
        }
        if ($stats['truncated_unresolved']) {
            $warnings[] = "Busy area: {$stats['truncated_unresolved']} search circle(s) still returned the provider's 20-station maximum, so some stations there may be missing.";
        }

        return [
            'source' => 'checkfuelprices',
            'attribution' => ['text' => $this->config['attribution'], 'url' => $this->config['attribution_url']],
            'warnings' => $warnings,
            'coverage' => [
                'circles_searched' => $stats['circles'],
                'http_requests' => $this->client->requests - $stats['requests_before'],
                'cache_hits' => $this->client->cacheHits - $stats['cache_hits_before'],
                'circles_split' => $stats['split'],
                'circles_truncated' => $stats['truncated_unresolved'],
                'circles_failed' => $stats['failed'],
                'circles_not_fetched' => $stats['not_fetched'],
            ],
        ];
    }

    private function clampRadius(float $miles): float
    {
        $min = (float) $this->config['min_radius_miles'];
        $max = (float) $this->config['max_radius_miles'];

        // Round UP to 0.1 so coverage is never reduced by rounding.
        return min($max, max($min, ceil($miles * 10) / 10));
    }

    /** @return list<float> cumulative distance (metres) at each vertex */
    private function cumulative(array $line): array
    {
        $out = [0.0];
        for ($i = 1, $n = count($line); $i < $n; $i++) {
            $out[] = $out[$i - 1] + Geo::distance($line[$i - 1][0], $line[$i - 1][1], $line[$i][0], $line[$i][1]);
        }

        return $out;
    }

    /** Point at a given distance along the line, interpolated. */
    private function pointAt(array $line, array $cumulative, float $distance): array
    {
        $n = count($line);
        for ($i = 1; $i < $n; $i++) {
            if ($cumulative[$i] >= $distance) {
                $segment = $cumulative[$i] - $cumulative[$i - 1];
                $t = $segment > 0 ? ($distance - $cumulative[$i - 1]) / $segment : 0.0;

                return [
                    $line[$i - 1][0] + $t * ($line[$i][0] - $line[$i - 1][0]),
                    $line[$i - 1][1] + $t * ($line[$i][1] - $line[$i - 1][1]),
                ];
            }
        }

        return $line[$n - 1];
    }
}
