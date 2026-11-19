<?php

namespace App\Console\Commands;

use App\Services\FuelFinder\FuelDataSync;
use Illuminate\Console\Command;

class SyncStations extends Command
{
    protected $signature = 'fuel:sync-stations';

    protected $description = 'Download all forecourts from Fuel Finder into the local database (scheduled hourly)';

    public function handle(FuelDataSync $sync): int
    {
        return SyncReport::run($this, fn () => $sync->syncStations());
    }
}
