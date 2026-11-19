<?php

namespace App\Providers;

use App\Services\FuelFinder\FixtureFuelFinderSource;
use App\Services\FuelFinder\FuelFinderClient;
use App\Services\FuelFinder\FuelFinderSource;
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
    }

    public function boot(): void
    {
        //
    }
}
