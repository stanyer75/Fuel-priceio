<?php

namespace App\Console\Commands;

use App\Services\FuelFinder\FuelDataSync;
use Illuminate\Console\Command;

class SyncPrices extends Command
{
    protected $signature = 'fuel:sync-prices {--full : Re-download every price instead of only recent changes}';

    protected $description = 'Sync fuel prices from Fuel Finder (incremental every 15 minutes, full nightly)';

    public function handle(FuelDataSync $sync): int
    {
        return SyncReport::run($this, fn () => $sync->syncPrices((bool) $this->option('full')));
    }
}
