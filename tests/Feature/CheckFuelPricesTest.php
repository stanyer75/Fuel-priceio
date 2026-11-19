<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesCheckFuelPrices;
use Tests\Concerns\FakesOpenRouteService;
use Tests\TestCase;

/** Live-price source: route coverage, the 20-result cap, and its error seams. */
class CheckFuelPricesTest extends TestCase
{
    use FakesCheckFuelPrices, FakesOpenRouteService, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
    }

    private function search(array $params = []): TestResponse
    {
        return $this->getJson('/api/journey?'.http_build_query($params + ['from' => 'Holyhead', 'to' => 'Manchester', 'fuel' => 'E10']));
    }

    /**
     * Fake both providers. Laravel uses the FIRST matching fake, so each
     * provider is faked exactly once, with any override merged in.
     */
    private function fakeBoth(array $cfpOverrides = []): void
    {
        $this->fakeCheckFuelPrices($cfpOverrides);
        $this->fakeOrs();
    }

    public function test_covers_the_route_with_circles_and_returns_live_stations_with_attribution(): void
    {
        $this->fakeBoth();

        $json = $this->search()->assertOk()->json();

        $ids = array_column($json['stations'], 'node_id');
        $this->assertContains('cfp:holyhead', $ids);
        $this->assertContains('cfp:ewloe', $ids);
        $this->assertSame(count($ids), count(array_unique($ids)), 'overlapping circles must be de-duplicated');

        $this->assertSame('checkfuelprices', $json['meta']['data_source']);
        $this->assertSame('Prices from checkfuelprices.co.uk', $json['meta']['attribution']['text']);
        $this->assertGreaterThan(5, $json['meta']['coverage']['circles_searched']);

        // Every circle is a valid request: radius within 1-50, limit 20, no redirects followed.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'cfp.test') && ((float) $r['radius'] < 1 || (float) $r['radius'] > 50));
    }

    public function test_truncated_circle_is_split_to_find_stations_hidden_behind_the_cap(): void
    {
        $this->fakeBoth();

        $json = $this->search()->assertOk()->json();

        $this->assertGreaterThanOrEqual(1, $json['meta']['coverage']['circles_split']);
        $this->assertContains('cfp:cheap-south-manc', array_column($json['stations'], 'node_id'),
            'the cheap station is only visible once the busy Manchester circle is split');
    }

    public function test_stale_cma_leftover_price_is_excluded_and_uk_timestamps_parse(): void
    {
        $this->fakeBoth();

        $json = $this->search()->assertOk()->json();

        $this->assertNotContains('cfp:stale', array_column($json['stations'], 'node_id'));
        $this->assertGreaterThanOrEqual(1, $json['excluded']['price_from_closed_scheme']);

        $llangefni = collect($json['stations'])->firstWhere('node_id', 'cfp:llangefni');
        $this->assertEqualsWithDelta(30, $llangefni['price_unchanged_hours'], 0.2, 'dd/mm/yyyy UK local time parsed correctly');
        $this->assertSame('Texaco', $llangefni['name']);
        $this->assertSame('Asda Bangor Superstore', collect($json['stations'])->firstWhere('node_id', 'cfp:bangor')['name'], 'whitespace collapsed');
    }

    public function test_redirect_for_bad_parameters_is_not_followed_and_is_reported(): void
    {
        $this->fakeBoth(['cfp.test/api/widget/stations*' => Http::response('<html>home</html>', 302, ['Location' => 'https://cfp.test/'])]);

        $this->search()->assertStatus(502)
            ->assertJsonPath('error.source', 'checkfuelprices')
            ->assertJsonPath('error.code', 'request_rejected');
    }

    public function test_html_200_instead_of_json_fails_loudly(): void
    {
        $this->fakeBoth(['cfp.test/api/widget/stations*' => Http::response('<html>Just a moment...</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->search()->assertStatus(502)->assertJsonPath('error.code', 'unexpected_response');
    }

    public function test_429_is_retried_honouring_retry_after(): void
    {
        $calls = 0;
        $this->fakeBoth(['cfp.test/api/widget/stations*' => function () use (&$calls) {
            return ++$calls === 1
                ? Http::response('', 429, ['Retry-After' => '3'])
                : Http::response(['success' => true, 'count' => 0, 'stations' => []]);
        }]);

        $this->search()->assertOk();
        Sleep::assertSlept(fn ($d) => (int) $d->totalSeconds === 3);
    }

    public function test_failing_circles_give_partial_results_with_a_warning(): void
    {
        $holyhead = $this->cfpPool()[0];
        $this->fakeBoth(['cfp.test/api/widget/stations*' => function (Request $request) use ($holyhead) {
            // Circles in the eastern half of the route fail with a persistent 500.
            return (float) $request['lng'] > -3.0
                ? Http::response('', 500)
                : Http::response(['success' => true, 'count' => 1, 'stations' => [$holyhead]]);
        }]);

        $json = $this->search()->assertOk()->json();

        $this->assertGreaterThan(0, $json['meta']['coverage']['circles_failed']);
        $this->assertStringContainsString('Partial coverage', implode(' ', $json['meta']['warnings']));
    }

    public function test_our_own_rate_cap_stops_the_search_politely(): void
    {
        config(['fueldetour.checkfuelprices.max_requests_per_minute' => 3, 'fueldetour.checkfuelprices.max_wait_seconds' => 0]);
        $this->fakeBoth();

        $json = $this->search()->assertOk()->json();

        $this->assertSame(3, $json['meta']['coverage']['http_requests']);
        $this->assertGreaterThan(0, $json['meta']['coverage']['circles_not_fetched']);
        $this->assertStringContainsString('local_rate_cap', implode(' ', $json['meta']['warnings']));
    }

    public function test_repeat_search_is_cached(): void
    {
        $this->fakeBoth();

        $this->search()->assertOk()->assertJsonPath('meta.from_cache', false);
        $sent = count(Http::recorded());
        $this->search()->assertOk()->assertJsonPath('meta.from_cache', true);

        $this->assertCount($sent, Http::recorded());
    }
}
