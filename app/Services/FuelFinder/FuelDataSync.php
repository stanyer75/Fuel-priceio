<?php

namespace App\Services\FuelFinder;

use App\Models\Station;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pulls data from a FuelFinderSource into our own tables.
 *
 * Why a local copy at all? Fuel Finder has no "near me" or spatial query,
 * only bulk paginated dumps, and allows one request at a time. So users
 * never wait on Fuel Finder: they query our database, which scheduled
 * syncs keep fresh (stations hourly, prices every 15 minutes).
 */
class FuelDataSync
{
    /** One lock for ALL Fuel Finder traffic: the API allows 1 concurrent request per client. */
    public const LOCK_NAME = 'fuel-finder:api-lock';

    public function __construct(
        private readonly FuelFinderSource $source,
        private readonly FuelFinderNormaliser $normaliser,
    ) {}

    public function syncStations(): SyncRun
    {
        return $this->run(SyncRun::STATIONS, null, function (SyncRun $run, array &$tally) {
            foreach ($this->source->stations() as $batch) {
                $run->batches++;
                $run->records_received += count($batch);

                $rows = [];
                foreach ($batch as $raw) {
                    $result = $this->normaliser->station(is_array($raw) ? $raw : []);
                    $this->count($tally, $result);

                    if ($result->accepted()) {
                        if (isset($rows[$result->row['node_id']])) {
                            $tally['rejections']['duplicate_in_feed'] = ($tally['rejections']['duplicate_in_feed'] ?? 0) + 1;
                        }
                        $rows[$result->row['node_id']] = $result->row; // last one wins
                    }
                }

                foreach (array_chunk(array_values($rows), 200) as $chunk) {
                    Station::upsert($chunk, ['node_id'], array_keys($chunk[0]));
                }
                $run->rows_saved += count($rows);
            }
        });
    }

    /**
     * Incremental by default (only prices changed since the last good run),
     * or full when asked or when there has never been a successful price sync.
     */
    public function syncPrices(bool $full = false): SyncRun
    {
        $since = null;
        $last = $full ? null : SyncRun::lastSuccessfulPrices();

        if ($last) {
            $since = $last->started_at->subMinutes((int) config('fueldetour.fuel_finder.incremental_overlap_minutes'));
        }

        $type = $since ? SyncRun::PRICES_INCREMENTAL : SyncRun::PRICES_FULL;

        return $this->run($type, $since, function (SyncRun $run, array &$tally) use ($since) {
            foreach ($this->source->prices($since) as $batch) {
                $run->batches++;
                $run->records_received += count($batch);

                $rows = [];
                foreach ($batch as $raw) {
                    foreach ($this->normaliser->prices(is_array($raw) ? $raw : []) as $result) {
                        $this->count($tally, $result);
                        if (! $result->accepted()) {
                            continue;
                        }

                        $key = $result->row['node_id'].'|'.$result->row['fuel_type'];
                        if (isset($rows[$key])) {
                            $tally['rejections']['duplicate_in_feed'] = ($tally['rejections']['duplicate_in_feed'] ?? 0) + 1;
                            if (($rows[$key]['price_updated_at'] ?? '') > ($result->row['price_updated_at'] ?? '')) {
                                continue; // keep the newer duplicate
                            }
                        }
                        $rows[$key] = $result->row;
                    }
                }

                $rows = $this->dropOlderThanStored($rows, $tally);
                $now = now();

                foreach (array_chunk(array_values($rows), 200) as $chunk) {
                    $chunk = array_map(fn ($row) => $row + ['created_at' => $now, 'updated_at' => $now], $chunk);
                    DB::table('station_prices')->upsert(
                        $chunk,
                        ['node_id', 'fuel_type'],
                        ['price_ppl', 'price_updated_at', 'data_corrections', 'updated_at'],
                    );
                }
                $run->rows_saved += count($rows);
            }
        });
    }

    /**
     * Seam: overlapping incremental windows and late submissions mean we can
     * receive a price that is OLDER than the one already stored. Never let
     * an older price overwrite a newer one.
     */
    private function dropOlderThanStored(array $rows, array &$tally): array
    {
        if ($rows === []) {
            return $rows;
        }

        $stored = DB::table('station_prices')
            ->whereIn('node_id', array_unique(array_column($rows, 'node_id')))
            ->get(['node_id', 'fuel_type', 'price_updated_at'])
            ->keyBy(fn ($p) => $p->node_id.'|'.$p->fuel_type);

        foreach ($rows as $key => $row) {
            $existing = $stored[$key]->price_updated_at ?? null;
            if ($existing && $row['price_updated_at'] && $row['price_updated_at'] < $existing) {
                unset($rows[$key]);
                $tally['rejections']['older_than_stored'] = ($tally['rejections']['older_than_stored'] ?? 0) + 1;
            }
        }

        return $rows;
    }

    private function run(string $type, ?CarbonImmutable $since, callable $work): SyncRun
    {
        return Cache::lock(self::LOCK_NAME, 900)->block(120, function () use ($type, $since, $work) {
            $run = SyncRun::create([
                'type' => $type,
                'source' => $this->source->name(),
                'status' => 'running',
                'changed_since' => $since,
                'started_at' => now(),
            ]);

            $requestsBefore = $this->source->requestCount();
            $tally = ['rejections' => [], 'corrections' => []];

            try {
                $work($run, $tally);
                $run->status = 'succeeded';
            } catch (Throwable $e) {
                // Rows already upserted are kept (they are valid); the run is
                // marked failed so the next incremental sync starts from the
                // last GOOD run and refetches anything we missed.
                $run->status = 'failed';
                $run->error = $e->getMessage();

                throw $e;
            } finally {
                $run->rows_rejected = array_sum($tally['rejections']);
                $run->rejections = $tally['rejections'] ?: null;
                $run->corrections = $tally['corrections'] ?: null;
                $run->requests_made = $this->source->requestCount() - $requestsBefore;
                $run->finished_at = now();
                $run->save();
            }

            return $run;
        });
    }

    private function count(array &$tally, NormalisedRecord $result): void
    {
        if ($result->rejection !== null) {
            $tally['rejections'][$result->rejection] = ($tally['rejections'][$result->rejection] ?? 0) + 1;
        }

        foreach ($result->corrections as $correction) {
            $tally['corrections'][$correction] = ($tally['corrections'][$correction] ?? 0) + 1;
        }
    }
}
