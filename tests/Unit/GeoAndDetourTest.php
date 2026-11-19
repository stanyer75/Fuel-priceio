<?php

namespace Tests\Unit;

use App\Services\Journey\DetourCalculator;
use App\Support\Geo;
use PHPUnit\Framework\TestCase;

class GeoAndDetourTest extends TestCase
{
    public function test_distance_holyhead_to_manchester_is_about_100_miles_as_the_crow_flies(): void
    {
        $miles = Geo::metresToMiles(Geo::distance(53.309, -4.633, 53.4794, -2.2453));

        $this->assertEqualsWithDelta(99.5, $miles, 2.0);
    }

    public function test_point_on_the_line_has_zero_distance_and_correct_progress(): void
    {
        $line = [[53.0, -3.0], [53.0, -2.0]];
        $mid = Geo::nearestOnPolyline(53.0, -2.5, $line);

        $this->assertEqualsWithDelta(0, $mid['distance'], 1);
        $this->assertEqualsWithDelta(Geo::distance(53.0, -3.0, 53.0, -2.5), $mid['along'], 50);
    }

    public function test_point_beside_the_line_measures_perpendicular_distance(): void
    {
        // 0.01 degrees of latitude ~ 1.11 km
        $result = Geo::nearestOnPolyline(53.01, -2.5, [[53.0, -3.0], [53.0, -2.0]]);

        $this->assertEqualsWithDelta(1112, $result['distance'], 15);
    }

    public function test_point_past_the_end_measures_to_the_endpoint(): void
    {
        $result = Geo::nearestOnPolyline(53.0, -1.9, [[53.0, -3.0], [53.0, -2.0]]);

        $this->assertEqualsWithDelta(Geo::distance(53.0, -1.9, 53.0, -2.0), $result['distance'], 5);
    }

    public function test_thinning_keeps_endpoints(): void
    {
        $points = array_map(fn ($i) => [53.0, -3.0 + $i * 0.0001], range(0, 100));
        $thin = Geo::thin($points, 200);

        $this->assertSame($points[0], $thin[0]);
        $this->assertSame($points[100], end($thin));
        $this->assertLessThan(count($points), count($thin));
    }

    public function test_detour_costs_use_uk_gallons(): void
    {
        $calc = new DetourCalculator(mpg: 45, litres: 40);

        // 4.5 miles at 45 mpg = 0.1 gallon = 0.454609 litres
        $this->assertEqualsWithDelta(0.454609, $calc->detourLitres(4.5), 1e-6);
        $this->assertEqualsWithDelta(40 * 1.40, $calc->fillCost(140.0), 1e-9);
        $this->assertEqualsWithDelta(56.0 + 0.454609 * 1.40, $calc->totalCost(140.0, 4.5), 1e-6);
    }

    public function test_cheaper_station_can_lose_once_the_detour_is_counted(): void
    {
        $calc = new DetourCalculator(mpg: 30, litres: 20);

        $onRoute = $calc->totalCost(142.9, 0);
        $detour = $calc->totalCost(139.9, 10); // 3p cheaper but 10 miles extra

        $this->assertGreaterThan($onRoute, $detour);
    }
}
