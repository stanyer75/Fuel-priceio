<?php

namespace App\Console\Commands;

use App\Services\FuelFinder\FuelDataSync;
use Illuminate\Console\Command;

class SyncAll extends Command
{
    protected $signature = 'fuel:sync';

    protected $description = 'First-time setup: sync all stations, then all prices';

    public function handle(FuelDataSync $sync): int
    {
        $this->info('Driver: '.config('fueldetour.fuel_finder.driver'));

        $stations = SyncReport::run($this, fn () => $sync->syncStations());
        if ($stations !== self::SUCCESS) {
            return $stations;
        }

        return SyncReport::run($this, fn () => $sync->syncPrices(full: true));
    }
}
