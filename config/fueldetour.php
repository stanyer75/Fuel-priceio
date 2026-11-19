<?php

/*
|--------------------------------------------------------------------------
| Fuel Detour configuration
|--------------------------------------------------------------------------
|
| Every tunable and every assumption about the two upstream APIs lives in
| this one file so it can be reviewed (and challenged) in one place.
| Values marked "UNVERIFIED" come from community sources rather than the
| official documentation and should be re-checked.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Where station prices come from
    |----------------------------------------------------------------------
    |
    | "checkfuelprices": live, on-demand radius queries along the route to
    |                    CheckFuelPrices (free, no key). Default.
    | "synced":          our local copy, filled by `php artisan fuel:sync`
    |                    from Fuel Finder (FUEL_FINDER_DRIVER=live) or the
    |                    bundled sample data (FUEL_FINDER_DRIVER=fixture).
    */
    'price_source' => env('PRICE_SOURCE', 'checkfuelprices'),

    /*
    |----------------------------------------------------------------------
    | CheckFuelPrices (https://checkfuelprices.co.uk/developers)
    |----------------------------------------------------------------------
    |
    | Third-party UK API built on the government Fuel Finder feed. The
    | stations endpoint is public but its response is undocumented, and NO
    | RATE LIMIT IS PUBLISHED, so the politeness settings below are our own
    | choice. Measure with: php artisan prices:probe
    */
    'checkfuelprices' => [
        'base_url' => env('CFP_BASE_URL', 'https://checkfuelprices.co.uk'),
        'stations_path' => '/api/widget/stations',
        'user_agent' => 'FuelDetour/1.0 (API integration demo)',
        'timeout_seconds' => 15,
        'max_retries' => 2,

        // VERIFIED by probe: results are distance-sorted; limit > 20 and
        // radius outside 1-50 miles are rejected (with a 302 redirect).
        'max_results' => 20,
        'min_radius_miles' => 1.0,
        'max_radius_miles' => 50.0,

        // Their terms recommend caching responses for 30 minutes.
        'cache_minutes' => 30,

        // Our own limits, as none are published.
        'min_request_interval_ms' => (int) env('CFP_MIN_INTERVAL_MS', 250),
        'max_requests_per_minute' => (int) env('CFP_MAX_PER_MINUTE', 60),
        'max_wait_seconds' => 10,

        // Route coverage: circles are split when they hit the 20-result cap,
        // down to this window length; a hard budget caps one search.
        'min_window_miles' => 1.0,
        'max_requests_per_search' => 60,

        'attribution' => 'Prices from checkfuelprices.co.uk',
        'attribution_url' => 'https://checkfuelprices.co.uk',
    ],

    /*
    |----------------------------------------------------------------------
    | Fuel Finder (UK statutory fuel price open data scheme)
    |----------------------------------------------------------------------
    |
    | driver: "fixture" reads the bundled sample files in
    |         tests/Fixtures/fuel-finder (FAKE stations and prices, so the
    |         app can be reviewed without credentials).
    |         "live" calls the real API using OAuth client credentials.
    */
    'fuel_finder' => [
        'driver' => env('FUEL_FINDER_DRIVER', 'fixture'),
        'base_url' => env('FUEL_FINDER_BASE_URL', 'https://www.fuel-finder.service.gov.uk'),
        'client_id' => env('FUEL_FINDER_CLIENT_ID'),
        'client_secret' => env('FUEL_FINDER_CLIENT_SECRET'),

        // Official docs: max 500 records per batch; a short batch is the last one.
        'batch_size' => 500,

        // Safety cap so a misbehaving paginator can never loop forever.
        // ~8,000 UK forecourts / 500 = ~16 batches, so 60 is generous.
        'max_batches' => 60,

        // Official docs (live): 100 requests/minute, 1 concurrent request.
        // A community copy of the spec says 30/minute, so we default to a
        // conservative 2.1s gap (~28/min). A full sync is ~35 requests,
        // so this costs about a minute and never trips the limit.
        'min_request_interval_ms' => (int) env('FUEL_FINDER_MIN_INTERVAL_MS', 2100),

        'max_retries' => 3,
        'timeout_seconds' => 30,

        // Refresh the token this many seconds before it expires (TTL is 3600s).
        'token_expiry_margin_seconds' => 120,

        // Incremental price syncs ask for changes since the last successful
        // run MINUS this overlap, to cover clock skew and late submissions.
        'incremental_overlap_minutes' => 10,

        // How long a sync waits for another sync to release the API lock.
        'lock_wait_seconds' => 120,

        'fixture_path' => base_path('tests/Fixtures/fuel-finder'),
    ],

    /*
    |----------------------------------------------------------------------
    | openrouteservice (HeiGIT) - geocoding, directions, matrix
    |----------------------------------------------------------------------
    |
    | api.openrouteservice.org is deprecated (10% quota since 27 Aug 2026);
    | api.heigit.org is the replacement host.
    */
    'routing' => [
        'base_url' => env('ORS_BASE_URL', 'https://api.heigit.org'),
        'api_key' => env('ORS_API_KEY'),

        // VERIFIED by live probe (Nov 2026): on api.heigit.org the services
        // live under different prefixes than on the old host:
        //   routing:   /openrouteservice/v2/{directions|matrix}/...
        //   geocoding: /pelias/v1/search   (old host: /geocode/search)
        // For the old host set ORS_ROUTING_PREFIX="" and
        // ORS_GEOCODE_PATH=/geocode/search.
        'routing_prefix' => env('ORS_ROUTING_PREFIX', '/openrouteservice'),
        'geocode_path' => env('ORS_GEOCODE_PATH', '/pelias/v1/search'),
        'profile' => 'driving-car',
        'timeout_seconds' => 20,

        // Free plan matrix quota: 500 requests/day (VERIFIED via the
        // X-Ratelimit-Limit header). Per-request location limit unverified,
        // so keep requests small.
        // Locations sent = 2 (start, end) + this many candidate stations.
        'matrix_max_candidates' => 25,
    ],

    /*
    |----------------------------------------------------------------------
    | Journey search & caching (cache durations from the Fuel Finder
    | performance guidelines: stations 1h, prices 15m, searches 5m)
    |----------------------------------------------------------------------
    */
    'journey' => [
        'search_cache_minutes' => 5,
        'geocode_cache_days' => 7,
        'route_cache_hours' => 24,

        // Defaults offered in the form.
        'default_corridor_miles' => 2.0,
        'default_mpg' => 45,
        'default_litres' => 40,

        // Longest journey we will search, to bound the bounding-box query.
        'max_route_miles' => 450,

        // Prices are shown with their age; older than this are flagged...
        'price_old_after_hours' => 48,
        // ...and older than this are excluded from results entirely.
        'price_max_age_days' => 14,

        // A detour must save at least this much (GBP) to be recommended.
        'min_worthwhile_saving_gbp' => 0.50,

        // Number of stations closest to the route that are always sent to
        // the matrix (so we can find the "convenient" baseline station).
        'nearest_candidates' => 5,
    ],
];
