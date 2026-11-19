<?php

namespace Tests\Feature;

use App\Services\Prices\CorridorFilter;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Which prices are shown, flagged or hidden, based on when they last changed. */
class CorridorFilterTest extends TestCase
{
    private function station(string $id, ?CarbonImmutable $changedAt, bool $closed = false): array
    {
        return [
            'node_id' => $id, 'name' => $id, 'brand' => null, 'address' => null, 'postcode' => null,
            'lat' => 53.0, 'lon' => -2.5, 'is_motorway_services' => false, 'is_supermarket' => false,
            'temporary_closure' => $closed, 'price_ppl' => 150.0, 'price_updated_at' => $changedAt,
            'data_corrections' => [],
        ];
    }

    public function test_price_age_rules(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));
        $now = CarbonImmutable::now();

        $result = (new CorridorFilter)->apply([
            $this->station('fresh', $now->subHours(3)),
            $this->station('stable-20-days', $now->subDays(20)),       // shown (flagged by the planner)
            $this->station('unchanged-70-days', $now->subDays(70)),    // > 60 days: hidden
            $this->station('cma-leftover', CarbonImmutable::parse('2026-04-29 11:18:22', 'UTC')), // closed scheme
            $this->station('no-date', null),                           // shown, age unknown
            $this->station('closed', $now->subHour(), closed: true),
        ], [[53.0, -3.0], [53.0, -2.0]], 2.0);

        $this->assertSame(['fresh', 'stable-20-days', 'no-date'], array_column($result['candidates'], 'node_id'));
        $this->assertSame([
            'temporarily_closed' => 1,
            'price_from_closed_scheme' => 1,
            'price_unchanged_too_long' => 1,
        ], $result['excluded']);
    }

    public function test_thresholds_are_configurable(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));
        config(['fueldetour.journey.price_max_age_days' => 10]);

        $result = (new CorridorFilter)->apply(
            [$this->station('stable-20-days', CarbonImmutable::now()->subDays(20))],
            [[53.0, -3.0], [53.0, -2.0]], 2.0,
        );

        $this->assertSame([], $result['candidates']);
        $this->assertSame(1, $result['excluded']['price_unchanged_too_long']);
    }
}
