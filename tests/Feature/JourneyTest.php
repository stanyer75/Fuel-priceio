<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesOpenRouteService;
use Tests\TestCase;

/** End-to-end: synced fixture data + faked openrouteservice. */
class JourneyTest extends TestCase
{
    use FakesOpenRouteService, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function search(array $params = []): TestResponse
    {
        return $this->getJson('/api/journey?'.http_build_query($params + ['from' => 'Holyhead', 'to' => 'Manchester', 'fuel' => 'E10']));
    }

    public function test_holyhead_to_manchester_returns_ranked_stations_and_a_verdict(): void
    {
        $this->artisan('fuel:sync')->assertSuccessful();
        $this->fakeOrs();

        $response = $this->search()->assertOk();
        $json = $response->json();

        $this->assertSame('Holyhead, Isle of Anglesey, Wales', $json['from']['label']);
        $this->assertContains($json['recommendation']['verdict'], ['stay_on_route', 'detour_worth_it', 'not_worth_it']);

        $ids = array_column($json['stations'], 'node_id');
        $this->assertNotContains('fixture-13-frodsham', $ids, 'temporarily closed station must be excluded');
        $this->assertNotContains('fixture-14-altrincham', $ids, '40-day-old price must be excluded');
        $this->assertNotContains('fixture-08-rhyl', $ids, 'Rhyl is outside the 2 mile corridor');
        $this->assertNotContains('fixture-16-wythenshawe', $ids, 'no E10 price');
        $this->assertContains('fixture-07-abergele', $ids, 'swapped coordinates were repaired so it is found');
        $this->assertSame(1, $json['excluded']['temporarily_closed']);
        $this->assertSame(1, $json['excluded']['price_too_old']);

        // Ranked by total cost, exactly one "convenient" baseline.
        $totals = array_column($json['stations'], 'total_cost_gbp');
        $sorted = $totals;
        sort($sorted);
        $this->assertSame($sorted, $totals);
        $this->assertCount(1, array_filter(array_column($json['stations'], 'is_convenient')));

        $abergele = collect($json['stations'])->firstWhere('node_id', 'fixture-07-abergele');
        $this->assertContains('coordinates_swapped', $abergele['data_corrections']);

        $this->assertContains('SAMPLE DATA: stations and prices are fictional fixtures, not real Fuel Finder data.', $json['meta']['warnings']);
        $this->assertSame(497, $json['meta']['ors_quota_remaining']);

        // The matrix is sent [lon, lat] - the GeoJSON order - not [lat, lon].
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v2/matrix/') && $r['locations'][0] === [-4.633, 53.309]);
    }

    public function test_wider_corridor_finds_more_stations(): void
    {
        $this->artisan('fuel:sync')->assertSuccessful();
        $this->fakeOrs();

        $narrow = count($this->search(['corridor_miles' => 2])->json('stations'));
        $wide = $this->search(['corridor_miles' => 5])->json('stations');

        $this->assertGreaterThan($narrow, count($wide));
        $this->assertContains('fixture-08-rhyl', array_column($wide, 'node_id'));
    }

    public function test_repeat_search_is_served_from_cache_without_calling_ors(): void
    {
        $this->artisan('fuel:sync')->assertSuccessful();
        $this->fakeOrs();

        $this->search()->assertOk()->assertJsonPath('meta.from_cache', false);
        $sent = count(Http::recorded());

        $this->search()->assertOk()->assertJsonPath('meta.from_cache', true);
        $this->assertCount($sent, Http::recorded());
    }

    public function test_new_price_sync_invalidates_cached_searches(): void
    {
        $this->artisan('fuel:sync')->assertSuccessful();
        $this->fakeOrs();
        $this->search()->assertOk();

        $this->artisan('fuel:sync-prices')->assertSuccessful();

        $this->search()->assertOk()->assertJsonPath('meta.from_cache', false);
    }

    public function test_no_synced_data_gives_actionable_error(): void
    {
        $this->fakeOrs();

        $this->search()->assertStatus(503)
            ->assertJsonPath('error.code', 'no_data')
            ->assertJsonPath('error.source', 'app');
    }

    public function test_unknown_place_is_a_422_naming_the_input(): void
    {
        $this->artisan('fuel:sync')->assertSuccessful();
        $this->fakeOrs();

        $this->search(['to' => 'Atlantis'])->assertStatus(422)
            ->assertJsonPath('error.code', 'place_not_found')
            ->assertJsonPath('error.message', 'Couldn\'t find a UK place matching "Atlantis".');
    }

    public function test_same_start_and_end_is_rejected(): void
    {
        $this->artisan('fuel:sync')->assertSuccessful();
        $this->fakeOrs();

        $this->search(['to' => 'Holyhead Town'])->assertStatus(422)->assertJsonPath('error.code', 'same_place');
    }

    public function test_ors_daily_quota_403_is_distinguished_from_a_bad_key(): void
    {
        $this->artisan('fuel:sync')->assertSuccessful();
        $this->fakeOrs(['ors.test/geocode/search*' => Http::response(['error' => 'Quota exceeded'], 403)]);

        $this->search()->assertStatus(503)->assertJsonPath('error.code', 'quota_exhausted');
    }

    public function test_ors_bad_key_403(): void
    {
        $this->artisan('fuel:sync')->assertSuccessful();
        $this->fakeOrs(['ors.test/geocode/search*' => Http::response(['error' => 'Access to this API has been disallowed'], 403)]);

        $this->search()->assertStatus(503)->assertJsonPath('error.code', 'invalid_api_key');
    }

    public function test_ors_route_not_found_maps_to_no_route(): void
    {
        $this->artisan('fuel:sync')->assertSuccessful();
        $this->fakeOrs(['ors.test/v2/directions/*' => Http::response(['error' => ['code' => 2009, 'message' => 'Route could not be found']], 404)]);

        $this->search()->assertStatus(422)->assertJsonPath('error.code', 'no_route');
    }

    public function test_unroutable_station_in_matrix_is_counted_not_fatal(): void
    {
        $this->artisan('fuel:sync')->assertSuccessful();
        $this->fakeOrs(['ors.test/v2/matrix/*' => function (Request $r) {
            $n = count($r['sources']);
            $row = fn ($i) => array_map(fn ($j) => ($i === 1 || $j === 1) && $i !== $j ? null : 1000.0 * ($i + $j + 1), range(0, $n - 1));
            $m = array_map($row, range(0, $n - 1));

            return Http::response(['distances' => $m, 'durations' => $m]);
        }]);

        $json = $this->search()->assertOk()->json();
        $this->assertSame(1, $json['excluded']['unroutable']);
    }

    public function test_missing_ors_key_is_explained(): void
    {
        $this->artisan('fuel:sync')->assertSuccessful();
        config(['fueldetour.routing.api_key' => null]);

        $this->search()->assertStatus(503)->assertJsonPath('error.code', 'missing_api_key');
    }

    public function test_validation_errors_are_json_422(): void
    {
        $this->getJson('/api/journey?from=Holyhead&fuel=LPG&mpg=500')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to', 'fuel', 'mpg']);
    }

    public function test_html_page_renders_results_and_errors(): void
    {
        $this->fakeOrs();
        $this->get('/')->assertOk()->assertSee('Fuel Detour');

        $this->artisan('fuel:sync')->assertSuccessful();

        $this->get('/?from=Holyhead&to=Manchester&fuel=E10')
            ->assertOk()
            ->assertSee('SAMPLE DATA')
            ->assertSee('Demo Fuels', false);

        $this->get('/?from=Holyhead&to=Atlantis')->assertOk()->assertSee('place_not_found');
    }

    public function test_status_endpoint_reports_sync_history(): void
    {
        $this->artisan('fuel:sync')->assertSuccessful();

        $this->getJson('/api/status')->assertOk()
            ->assertJsonPath('data_source', 'fixture')
            ->assertJsonPath('stations', 17)
            ->assertJsonCount(2, 'recent_runs')
            ->assertJsonPath('recent_runs.1.rejections.invalid_coordinates', 1);
    }
}
