<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\StationPrice;
use App\Models\SyncRun;
use App\Services\FuelFinder\FuelDataSync;
use App\Services\FuelFinder\FuelFinderException;
use App\Services\FuelFinder\FuelFinderSource;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FuelDataSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_sync_repairs_and_rejects_known_feed_defects(): void
    {
        $this->artisan('fuel:sync')->assertSuccessful();

        $stations = SyncRun::where('type', SyncRun::STATIONS)->sole();
        $this->assertSame('succeeded', $stations->status);
        $this->assertSame(17, Station::count());
        $this->assertSame(['invalid_coordinates' => 1, 'duplicate_in_feed' => 1], $stations->rejections);
        $this->assertEquals(1, $stations->corrections['coordinates_swapped']);
        $this->assertEquals(1, $stations->corrections['longitude_sign_flipped']);

        $abergele = Station::where('node_id', 'fixture-07-abergele')->sole();
        $this->assertSame([53.286, -3.58], [$abergele->latitude, $abergele->longitude]);

        $pounds = StationPrice::where('node_id', 'fixture-05-llandudno-junction')->where('fuel_type', 'E10')->sole();
        $this->assertSame(141.9, $pounds->price_ppl);

        $csvCode = StationPrice::where('node_id', 'fixture-12-chester')->where('fuel_type', 'B7_STANDARD')->sole();
        $this->assertSame(139.9, $csvCode->price_ppl);

        $prices = SyncRun::where('type', SyncRun::PRICES_FULL)->sole();
        $this->assertEquals(['implausible_price' => 1, 'no_price' => 1, 'unknown_fuel_type' => 1], $prices->rejections);
    }

    public function test_second_price_sync_is_incremental_from_last_good_run(): void
    {
        $this->artisan('fuel:sync')->assertSuccessful();
        $this->artisan('fuel:sync-prices')->assertSuccessful();

        $run = SyncRun::latest('id')->first();
        $this->assertSame(SyncRun::PRICES_INCREMENTAL, $run->type);
        $this->assertNotNull($run->changed_since);
    }

    public function test_an_older_price_never_overwrites_a_newer_one(): void
    {
        $this->useSource(prices: [[
            ['node_id' => 'n1', 'fuel_prices' => [['fuel_type' => 'E10', 'price' => '0140.0000', 'price_last_updated' => '2026-11-18T10:00:00']]],
        ]]);
        app(FuelDataSync::class)->syncPrices(full: true);

        $this->useSource(prices: [[
            ['node_id' => 'n1', 'fuel_prices' => [['fuel_type' => 'E10', 'price' => '0150.0000', 'price_last_updated' => '2026-11-18T09:00:00']]],
        ]]);
        $run = app(FuelDataSync::class)->syncPrices(full: true);

        $this->assertSame(140.0, StationPrice::sole()->price_ppl);
        $this->assertSame(['older_than_stored' => 1], $run->rejections);
    }

    public function test_failed_sync_is_recorded_and_keeps_existing_data(): void
    {
        $this->artisan('fuel:sync')->assertSuccessful();
        $before = StationPrice::count();

        $this->useSource(prices: [[]], throw: FuelFinderException::rateLimited());
        $this->artisan('fuel:sync-prices', ['--full' => true])
            ->expectsOutputToContain('[fuel_finder:rate_limited]')
            ->assertFailed();

        $failed = SyncRun::latest('id')->first();
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('429', $failed->error);
        $this->assertSame($before, StationPrice::count());

        // The next incremental run starts from the last GOOD run, not the failed one.
        $this->assertNotSame($failed->id, SyncRun::lastSuccessfulPrices()->id);
    }

    public function test_concurrent_sync_is_refused_because_api_allows_one_request_at_a_time(): void
    {
        config(['fueldetour.fuel_finder.lock_wait_seconds' => 0]);
        $lock = Cache::lock(FuelDataSync::LOCK_NAME, 60);
        $lock->get();

        $this->artisan('fuel:sync-stations')
            ->expectsOutputToContain('Another Fuel Finder sync is still running')
            ->assertFailed();

        $lock->release();
    }

    /** Swap in a scripted source for edge cases the fixture files don't cover. */
    private function useSource(array $stations = [], array $prices = [], ?\Throwable $throw = null): void
    {
        $this->app->bind(FuelFinderSource::class, fn () => new class($stations, $prices, $throw) implements FuelFinderSource
        {
            public function __construct(private array $stations, private array $prices, private ?\Throwable $throw) {}

            public function stations(): iterable
            {
                return $this->stations;
            }

            public function prices(?CarbonInterface $changedSince = null): iterable
            {
                foreach ($this->prices as $batch) {
                    if ($this->throw) {
                        throw $this->throw;
                    }
                    yield $batch;
                }
            }

            public function requestCount(): int
            {
                return 0;
            }

            public function name(): string
            {
                return 'test';
            }
        });
    }
}
