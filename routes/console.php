<?php

use Illuminate\Support\Facades\Schedule;

/*
| Sync schedule, following the Fuel Finder performance guidelines
| (stations change rarely: 1 hour; prices: 15 minutes).
|
| Run locally with:  php artisan schedule:work
| In production:     * * * * * php /path/to/artisan schedule:run
|
| All three commands share one cache lock inside FuelDataSync, so they can
| never call the API concurrently (Fuel Finder allows 1 request at a time).
*/

Schedule::command('fuel:sync-stations')->hourly()->withoutOverlapping();
Schedule::command('fuel:sync-prices')->everyFifteenMinutes()->withoutOverlapping();

// Nightly full refresh catches anything an incremental window missed.
Schedule::command('fuel:sync-prices --full')->dailyAt('03:30')->withoutOverlapping();
