<?php

namespace Tests\Concerns;

use App\Support\Geo;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A fake openrouteservice that answers in ORS's real response shapes,
 * including its [lon, lat] coordinate order.
 *
 * The matrix is computed from the request: straight-line distance x 1.3
 * (a typical road "winding factor") at 50 mph, so detour maths is real.
 */
trait FakesOpenRouteService
{
    /** Rough A55 / A494 / M56 corridor, [lat, lon]. */
    protected array $holyheadToManchester = [
        [53.3090, -4.6330], [53.2840, -4.5550], [53.2500, -4.3000], [53.2250, -4.1000],
        [53.2840, -3.8100], [53.2860, -3.5800], [53.2620, -3.4400], [53.1980, -3.1030],
        [53.1980, -3.0600], [53.2900, -2.7300], [53.3700, -2.3700], [53.3700, -2.2700],
        [53.4794, -2.2453],
    ];

    protected array $places = [
        'holyhead' => ['Holyhead, Isle of Anglesey, Wales', 53.3090, -4.6330],
        'manchester' => ['Manchester, England', 53.4794, -2.2453],
        'holyhead town' => ['Holyhead Town, Wales', 53.3100, -4.6320],
    ];

    protected function fakeOrs(array $overrides = []): void
    {
        config(['fueldetour.routing.api_key' => 'test-key', 'fueldetour.routing.base_url' => 'https://ors.test']);

        Http::fake($overrides + [
            'ors.test/geocode/search*' => function (Request $request) {
                $match = $this->places[strtolower($request['text'])] ?? null;

                return Http::response([
                    'type' => 'FeatureCollection',
                    'features' => $match ? [[
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [$match[2], $match[1]]],
                        'properties' => ['label' => $match[0]],
                    ]] : [],
                ], 200, ['x-ratelimit-remaining' => '999']);
            },
            'ors.test/v2/directions/*' => fn () => Http::response([
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'geometry' => ['type' => 'LineString', 'coordinates' => array_map(fn ($p) => [$p[1], $p[0]], $this->holyheadToManchester)],
                    'properties' => ['summary' => ['distance' => 165000.0, 'duration' => 7200.0]],
                ]],
            ], 200, ['x-ratelimit-remaining' => '1998']),
            'ors.test/v2/matrix/*' => function (Request $request) {
                $loc = $request['locations'];
                $distances = $durations = [];
                foreach ($request['sources'] as $i => $s) {
                    foreach ($request['destinations'] as $j => $d) {
                        $m = Geo::distance($loc[$s][1], $loc[$s][0], $loc[$d][1], $loc[$d][0]) * 1.3;
                        $distances[$i][$j] = round($m, 1);
                        $durations[$i][$j] = round($m / 22.35, 1); // 50 mph in m/s
                    }
                }

                return Http::response(['distances' => $distances, 'durations' => $durations], 200, ['x-ratelimit-remaining' => '497']);
            },
        ]);
    }
}
