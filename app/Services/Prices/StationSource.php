<?php

namespace App\Services\Prices;

use App\Enums\FuelType;

/**
 * Finds priced stations near a route. Implementations:
 *  - CheckFuelPricesStationSource: live radius queries along the route
 *  - SyncedStationSource: our synced copy of Fuel Finder / sample data
 */
interface StationSource
{
    /**
     * @param  list<array{0: float, 1: float}>  $route  [lat, lon]
     * @return array{
     *     candidates: list<array>,
     *     excluded: array<string, int>,
     *     meta: array{source: string, attribution: ?array, warnings: list<string>}
     * }
     */
    public function find(array $route, FuelType $fuel, float $corridorMiles): array;

    /**
     * Part of the search-result cache key: change it and every cached
     * search for this source is invalidated.
     */
    public function cacheVersion(): string;
}
