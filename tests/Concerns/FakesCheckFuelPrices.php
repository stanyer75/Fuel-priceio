<?php

namespace Tests\Concerns;

use App\Support\Geo;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A fake CheckFuelPrices that behaves like the real one (as probed):
 *  - returns stations within `radius` miles, sorted by distance;
 *  - caps results at `limit` (max 20);
 *  - answers out-of-range parameters with a 302 redirect, not a 400;
 *  - mixes ISO and UK day-first timestamps.
 */
trait FakesCheckFuelPrices
{
    /** @var list<array> raw provider rows */
    protected array $cfpStations = [];

    protected function cfpPool(): array
    {
        $now = now()->utc();
        $iso = fn ($hoursAgo) => $now->copy()->subHours($hoursAgo)->format('Y-m-d\TH:i:s.000\Z');
        $uk = fn ($hoursAgo) => $now->copy()->subHours($hoursAgo)->timezone('Europe/London')->format('d/m/Y H:i:s');
        $row = fn ($id, $brand, $lat, $lng, $price, $updated, $postcode = 'LL00 0AA') => [
            'id' => $id, 'brand' => $brand, 'address' => "{$brand} FORECOURT, TEST ROAD, Wales", 'postcode' => $postcode,
            'slug' => strtolower($id), 'price' => $price, 'distance' => 0, 'lat' => $lat, 'lng' => $lng,
            'price_updated' => $updated, 'diff_from_avg' => 0,
        ];

        $pool = [
            $row('holyhead', 'ESSO', 53.3065, -4.6280, 157.9, $iso(3)),
            $row('llangefni', 'TEXACO', 53.2520, -4.3050, 148.9, $uk(30)),          // UK day-first timestamp
            $row('bangor', 'ASDA BANGOR  SUPERSTORE', 53.2240, -4.1020, 151.7, $iso(5)), // double space, as seen live
            $row('stale', 'TESCO', 53.2840, -3.8110, 129.9, '29/04/2026 11:18:22'), // leftover CMA price
            $row('abergele', 'SHELL', 53.2860, -3.5810, 163.9, $iso(12)),
            $row('ewloe', 'JET', 53.1985, -3.0610, 154.9, $iso(2)),
            $row('cheap-south-manc', 'COSTCO', 53.4400, -2.2540, 145.9, $iso(1)),
        ];

        // A dense cluster in central Manchester: more than the 20-result cap.
        for ($i = 0; $i < 25; $i++) {
            $pool[] = $row("manc-{$i}", 'SHELL', 53.4794 + ($i % 5) * 0.0008, -2.2453 + intdiv($i, 5) * 0.0008, 165.9 + $i / 10, $iso(4), 'M1 1AA');
        }

        return $pool;
    }

    protected function fakeCheckFuelPrices(array $overrides = []): void
    {
        config([
            'fueldetour.price_source' => 'checkfuelprices',
            'fueldetour.checkfuelprices.base_url' => 'https://cfp.test',
        ]);
        $this->cfpStations = $this->cfpPool();

        Http::fake($overrides + [
            'cfp.test/api/widget/stations*' => function (Request $request) {
                $radius = (float) $request['radius'];
                $limit = (int) $request['limit'];
                if ($radius < 1 || $radius > 50 || $limit > 20 || isset($request['sort'])) {
                    return Http::response('', 302, ['Location' => 'https://cfp.test/']);
                }

                $hits = [];
                foreach ($this->cfpStations as $s) {
                    $miles = Geo::metresToMiles(Geo::distance((float) $request['lat'], (float) $request['lng'], $s['lat'], $s['lng']));
                    if ($miles <= $radius) {
                        $hits[] = ['distance' => round($miles, 1)] + $s;
                    }
                }
                usort($hits, fn ($a, $b) => $a['distance'] <=> $b['distance']);
                $hits = array_slice($hits, 0, $limit);

                return Http::response([
                    'success' => true, 'count' => count($hits), 'fuel' => $request['fuel'],
                    'area_stats' => ['count' => count($hits)], 'stations' => $hits,
                ]);
            },
        ]);
    }
}
