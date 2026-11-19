<?php

namespace App\Services\FuelFinder;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Serves the bundled sample files in tests/Fixtures/fuel-finder so the app
 * can be run and reviewed without Fuel Finder credentials.
 *
 * THE STATIONS AND PRICES IN THESE FILES ARE FAKE. They are shaped exactly
 * like real API responses and deliberately include the data problems seen
 * in the real feed (swapped coordinates, prices in pounds, duplicates...).
 *
 * So the sample prices never look "stale", every price timestamp is shifted
 * so the newest one is 20 minutes old at the moment the sync runs.
 */
class FixtureFuelFinderSource implements FuelFinderSource
{
    public function __construct(private readonly string $path, private readonly int $batchSize = 500) {}

    public function name(): string
    {
        return 'fixture';
    }

    public function requestCount(): int
    {
        return 0;
    }

    public function stations(): iterable
    {
        return array_chunk($this->load('pfs.json'), $this->batchSize);
    }

    public function prices(?CarbonInterface $changedSince = null): iterable
    {
        $records = $this->load('fuel-prices.json');
        $shift = $this->timeShiftSeconds($records);

        foreach ($records as &$record) {
            foreach ($record['fuel_prices'] ?? [] as $i => $entry) {
                if (! empty($entry['price_last_updated'])) {
                    $record['fuel_prices'][$i]['price_last_updated'] = CarbonImmutable::parse($entry['price_last_updated'], 'UTC')
                        ->addSeconds($shift)->format('Y-m-d\TH:i:s');
                }
            }
        }
        unset($record);

        if ($changedSince) {
            // Mimic the API's effective-start-timestamp filter.
            $records = array_values(array_filter(array_map(function ($record) use ($changedSince) {
                $record['fuel_prices'] = array_values(array_filter(
                    $record['fuel_prices'] ?? [],
                    fn ($e) => ! empty($e['price_last_updated']) && CarbonImmutable::parse($e['price_last_updated'], 'UTC')->gte($changedSince),
                ));

                return $record;
            }, $records), fn ($record) => $record['fuel_prices'] !== []));
        }

        return array_chunk($records, $this->batchSize);
    }

    private function timeShiftSeconds(array $records): int
    {
        $newest = null;
        foreach ($records as $record) {
            foreach ($record['fuel_prices'] ?? [] as $entry) {
                if (! empty($entry['price_last_updated'])) {
                    $t = CarbonImmutable::parse($entry['price_last_updated'], 'UTC');
                    $newest = $newest === null || $t->gt($newest) ? $t : $newest;
                }
            }
        }

        return $newest ? (int) $newest->diffInSeconds(now()->subMinutes(20), false) : 0;
    }

    private function load(string $file): array
    {
        $full = rtrim($this->path, '/\\').DIRECTORY_SEPARATOR.$file;
        if (! is_file($full)) {
            throw new RuntimeException("Fixture file not found: {$full}");
        }

        $data = json_decode(file_get_contents($full), true, flags: JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }
}
