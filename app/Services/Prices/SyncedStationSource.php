<?php

namespace App\Services\Prices;

use App\Enums\FuelType;
use App\Exceptions\UpstreamException;
use App\Models\Station;
use App\Models\SyncRun;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stations from our synced copy (Fuel Finder, or the bundled sample data).
 *
 * Seam: Fuel Finder only offers bulk dumps, so `fuel:sync` keeps a local
 * copy and this class does the spatial search: an indexed bounding-box
 * prefilter in SQL, then the exact corridor test in CorridorFilter.
 */
class SyncedStationSource implements StationSource
{
    public function __construct(private readonly CorridorFilter $filter) {}

    public function find(array $route, FuelType $fuel, float $corridorMiles): array
    {
        if (! Station::query()->exists()) {
            throw new UpstreamException('app', 'no_data',
                'No fuel station data has been synced yet. Run: php artisan fuel:sync', 503);
        }

        $line = Geo::thin($route, 200);
        $box = Geo::boundingBox($line, Geo::milesToMetres($corridorMiles));

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
            ->get()
            ->map(fn ($row) => [
                'node_id' => $row->node_id,
                'name' => $row->trading_name,
                'brand' => $row->brand_name,
                'address' => trim(implode(', ', array_filter([$row->address_line_1, $row->city]))),
                'postcode' => $row->postcode,
                'lat' => (float) $row->latitude,
                'lon' => (float) $row->longitude,
                'is_motorway_services' => (bool) $row->is_motorway_service_station,
                'is_supermarket' => (bool) $row->is_supermarket_service_station,
                'temporary_closure' => (bool) $row->temporary_closure,
                'price_ppl' => (float) $row->price_ppl,
                'price_updated_at' => $row->price_updated_at ? CarbonImmutable::parse($row->price_updated_at, 'UTC') : null,
                'data_corrections' => array_values(array_unique(array_merge(
                    json_decode($row->station_corrections ?? '[]', true) ?: [],
                    json_decode($row->price_corrections ?? '[]', true) ?: [],
                ))),
            ]);

        $found = $this->filter->apply($rows, $line, $corridorMiles);

        return $found + ['meta' => $this->meta()];
    }

    private function meta(): array
    {
        $driver = config('fueldetour.fuel_finder.driver');
        $prices = SyncRun::lastSuccessfulPrices();
        $warnings = [];

        if ($driver === 'fixture') {
            $warnings[] = 'SAMPLE DATA: stations and prices are fictional fixtures, not real Fuel Finder data.';
        }
        if (! $prices || $prices->finished_at?->lt(now()->subHour())) {
            $warnings[] = 'Prices have not synced successfully in the last hour; they may be out of date.';
        }

        return [
            'source' => "fuel_finder_{$driver}",
            'attribution' => $driver === 'live'
                ? ['text' => 'Contains UK Fuel Finder data', 'url' => 'https://www.gov.uk/government/collections/fuel-finder']
                : null,
            'warnings' => $warnings,
            'prices_synced_at' => $prices?->finished_at?->toIso8601String(),
        ];
    }

    /** New prices => new version => every cached search is invalidated. */
    public function cacheVersion(): string
    {
        return 'synced-'.(SyncRun::lastSuccessfulPrices()?->id ?? 0);
    }
}
