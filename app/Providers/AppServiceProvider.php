<?php

namespace App\Providers;

use App\Services\CheckFuelPrices\CheckFuelPricesClient;
use App\Services\CheckFuelPrices\CheckFuelPricesNormaliser;
use App\Services\CheckFuelPrices\CheckFuelPricesStationSource;
use App\Services\FuelFinder\FixtureFuelFinderSource;
use App\Services\FuelFinder\FuelFinderClient;
use App\Services\FuelFinder\FuelFinderSource;
use App\Services\Prices\CorridorFilter;
use App\Services\Prices\StationSource;
use App\Services\Prices\SyncedStationSource;
use App\Services\Routing\OpenRouteServiceClient;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // FUEL_FINDER_DRIVER picks the real API or the bundled sample data.
        $this->app->bind(FuelFinderSource::class, function () {
            $config = config('fueldetour.fuel_finder');

            return match ($config['driver']) {
                'live' => new FuelFinderClient($config),
                'fixture' => new FixtureFuelFinderSource($config['fixture_path'], (int) $config['batch_size']),
                default => throw new InvalidArgumentException("Unknown FUEL_FINDER_DRIVER \"{$config['driver']}\" (use live or fixture)."),
            };
        });

        // Singleton so the captured quota header survives for the request.
        $this->app->singleton(OpenRouteServiceClient::class, fn () => new OpenRouteServiceClient(config('fueldetour.routing')));

        // PRICE_SOURCE picks where station prices come from for a search.
        $this->app->singleton(CheckFuelPricesClient::class, fn () => new CheckFuelPricesClient(config('fueldetour.checkfuelprices')));
        $this->app->bind(StationSource::class, function ($app) {
            return match (config('fueldetour.price_source')) {
                'checkfuelprices' => new CheckFuelPricesStationSource(
                    $app->make(CheckFuelPricesClient::class),
                    $app->make(CheckFuelPricesNormaliser::class),
                    $app->make(CorridorFilter::class),
                    config('fueldetour.checkfuelprices'),
                ),
                'synced' => $app->make(SyncedStationSource::class),
                default => throw new InvalidArgumentException('Unknown PRICE_SOURCE "'.config('fueldetour.price_source').'" (use checkfuelprices or synced).'),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
