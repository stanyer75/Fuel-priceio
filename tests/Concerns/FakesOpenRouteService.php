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

    /** query => list of [label, lat, lon, layer, match_type] (as seen from the live geocoder) */
    protected array $places = [
        'holyhead' => [['Holyhead, Isle of Anglesey, Wales', 53.3090, -4.6330, 'locality', 'exact']],
        'manchester' => [['Manchester, England', 53.4794, -2.2453, 'locality', 'exact']],
        'holyhead town' => [['Holyhead Town, Wales', 53.3100, -4.6320, 'locality', 'exact']],
        // Two real towns far apart: genuinely ambiguous.
        'newport' => [
            ['Newport, England', 50.7010, -1.2880, 'locality', 'exact'],
            ['Newport, Wales', 51.5880, -2.9980, 'locality', 'exact'],
        ],
        // Not a UK place: only streets and venues that CONTAIN the name.
        'dublin' => [
            ['Dublin Road, Northern Ireland', 54.5800, -5.9300, 'street', 'exact'],
            ['Dublin - Liverpool, Bootle, England', 53.4460, -3.0170, 'venue', 'exact'],
            ['The Dublin Castle, London, England', 51.5380, -0.1460, 'venue', 'exact'],
        ],
        // A venue people really do type, with duplicate entries close together.
        'manchester airport' => [
            ['Manchester Airport, Ringway, England', 53.3650, -2.2720, 'venue', 'exact'],
            ['Manchester Airport, Ringway, England', 53.3655, -2.2730, 'venue', 'exact'],
        ],
        // Only results whose NAME merely contains the query: not a match.
        'smethwickish' => [['Londonderry, Smethwick, England', 52.4930, -1.9700, 'neighbourhood', 'fallback']],
        // Live: the correct Belfast is flagged "fallback"; it must still be used.
        'belfast' => [['Belfast, Northern Ireland', 54.5970, -5.9300, 'locality', 'fallback']],
        // Live: one place, two names.
        'derry' => [['Londonderry / Derry, Northern Ireland', 54.9966, -7.3086, 'locality', 'exact']],
    ];

    protected function fakeOrs(array $overrides = []): void
    {
        config(['fueldetour.routing.api_key' => 'test-key', 'fueldetour.routing.base_url' => 'https://ors.test']);

        Http::fake($overrides + [
            'ors.test/pelias/v1/search*' => function (Request $request) {
                $wantVenues = ($request['layers'] ?? '') === 'venue';
                $name = strtolower(trim(explode(',', $request['text'])[0]));
                $matches = array_filter(
                    $this->places[$name] ?? [],
                    fn ($m) => ($m[3] === 'venue') === $wantVenues,
                );

                return Http::response([
                    'type' => 'FeatureCollection',
                    'features' => array_values(array_map(fn ($m) => [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [$m[2], $m[1]]],
                        // Pelias: "name" is the place itself, "label" adds its context.
                        'properties' => ['name' => trim(explode(',', $m[0])[0]), 'label' => $m[0],
                            'layer' => $m[3], 'match_type' => $m[4], 'confidence' => 1],
                    ], $matches)),
                ], 200, ['x-ratelimit-remaining' => '999']);
            },
            // Like the real API: /geojson answers 406 unless geo+json is acceptable.
            'ors.test/openrouteservice/v2/directions/*' => fn (Request $request) => ! str_contains($request->header('Accept')[0] ?? '', 'application/geo+json')
                ? Http::response(['error' => ['code' => 2007, 'message' => 'This response format is not supported']], 406)
                : Http::response([
                    'type' => 'FeatureCollection',
                    'features' => [[
                        'type' => 'Feature',
                        'geometry' => ['type' => 'LineString', 'coordinates' => array_map(fn ($p) => [$p[1], $p[0]], $this->holyheadToManchester)],
                        'properties' => ['summary' => ['distance' => 165000.0, 'duration' => 7200.0]],
                    ]],
                ], 200, ['x-ratelimit-remaining' => '1998']),
            'ors.test/openrouteservice/v2/matrix/*' => function (Request $request) {
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
