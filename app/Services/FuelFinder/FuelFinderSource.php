<?php

namespace App\Services\FuelFinder;

use Carbon\CarbonInterface;

/**
 * Where raw Fuel Finder records come from. Two implementations:
 *  - FuelFinderClient:  the real API (OAuth, batched, rate limited)
 *  - FixtureFuelFinderSource: bundled sample files for offline review
 *
 * Both yield batches of RAW records exactly as the API shapes them; all
 * cleaning happens in FuelFinderNormaliser so it is tested once.
 */
interface FuelFinderSource
{
    /** @return iterable<int, array<int, array>> batches of raw station records */
    public function stations(): iterable;

    /** @return iterable<int, array<int, array>> batches of raw price records */
    public function prices(?CarbonInterface $changedSince = null): iterable;

    /** Number of HTTP requests made so far (0 for fixtures). */
    public function requestCount(): int;

    /** "live" or "fixture", recorded against each sync run. */
    public function name(): string;
}
