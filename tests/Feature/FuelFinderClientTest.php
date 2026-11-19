<?php

namespace Tests\Feature;

use App\Services\FuelFinder\FuelFinderClient;
use App\Services\FuelFinder\FuelFinderException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** Auth, pagination, rate-limit and error seams of the Fuel Finder API. */
class FuelFinderClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Http::preventStrayRequests();
    }

    private function client(array $overrides = []): FuelFinderClient
    {
        return new FuelFinderClient(array_replace(config('fueldetour.fuel_finder'), [
            'client_id' => 'id', 'client_secret' => 'secret', 'base_url' => 'https://ff.test',
        ], $overrides));
    }

    private function tokenResponse(string $token = 'tok-1'): array
    {
        return ['success' => true, 'data' => ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 3600], 'message' => 'Operation successful'];
    }

    private function rows(int $n): array
    {
        return array_map(fn ($i) => ['node_id' => "n{$i}"], range(1, $n));
    }

    private function collect(iterable $batches): array
    {
        $out = [];
        foreach ($batches as $batch) {
            $out[] = count($batch);
        }

        return $out;
    }

    public function test_pages_until_a_short_batch_and_reuses_one_token(): void
    {
        Http::fake([
            'ff.test/api/v1/oauth/*' => Http::response($this->tokenResponse()),
            'ff.test/api/v1/pfs*' => Http::sequence()->push($this->rows(500))->push($this->rows(500))->push($this->rows(7)),
        ]);

        $this->assertSame([500, 500, 7], $this->collect($this->client()->stations()));

        Http::assertSentCount(4); // 1 token + 3 batches
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'batch-number=3') && $r->hasHeader('Authorization', 'Bearer tok-1'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth/generate_access_token')
            && $r['client_id'] === 'id' && $r['client_secret'] === 'secret');
    }

    public function test_exactly_full_last_batch_ends_on_empty_or_404(): void
    {
        Http::fake([
            'ff.test/api/v1/oauth/*' => Http::response($this->tokenResponse()),
            'ff.test/api/v1/pfs*' => Http::sequence()->push($this->rows(500))->push(['message' => 'Not found'], 404),
        ]);

        $this->assertSame([500], $this->collect($this->client()->stations()));
    }

    public function test_runaway_pagination_is_capped(): void
    {
        Http::fake([
            'ff.test/api/v1/oauth/*' => Http::response($this->tokenResponse()),
            'ff.test/api/v1/pfs*' => Http::response($this->rows(500)),
        ]);

        $this->expectExceptionObject(FuelFinderException::tooManyBatches('/api/v1/pfs', 3));
        $this->collect($this->client(['max_batches' => 3])->stations());
    }

    public function test_incremental_prices_send_the_documented_timestamp_format(): void
    {
        Http::fake([
            'ff.test/api/v1/oauth/*' => Http::response($this->tokenResponse()),
            'ff.test/api/v1/pfs/fuel-prices*' => Http::response([]),
        ]);

        $this->collect($this->client()->prices(now()->setDateTime(2026, 11, 18, 9, 5, 0)));

        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'effective-start-timestamp=2026-11-18 09:05:00'));
    }

    public function test_429_is_retried_with_backoff_honouring_retry_after(): void
    {
        Http::fake([
            'ff.test/api/v1/oauth/*' => Http::response($this->tokenResponse()),
            'ff.test/api/v1/pfs*' => Http::sequence()
                ->push('', 429, ['Retry-After' => '7'])
                ->push($this->rows(3)),
        ]);

        $this->assertSame([3], $this->collect($this->client()->stations()));
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 7);
    }

    public function test_persistent_429_gives_a_clear_rate_limit_error(): void
    {
        Http::fake([
            'ff.test/api/v1/oauth/*' => Http::response($this->tokenResponse()),
            'ff.test/api/v1/pfs*' => Http::response('', 429),
        ]);

        try {
            $this->collect($this->client(['max_retries' => 2])->stations());
            $this->fail('Expected exception');
        } catch (FuelFinderException $e) {
            $this->assertSame('rate_limited', $e->errorCode);
        }
    }

    public function test_401_on_data_endpoint_reauthenticates_once(): void
    {
        Http::fake([
            'ff.test/api/v1/oauth/*' => Http::sequence()->push($this->tokenResponse('old'))->push($this->tokenResponse('new')),
            'ff.test/api/v1/pfs*' => Http::sequence()
                ->push(['error' => 'Unauthorized', 'message' => 'Invalid API key or missing authentication header.'], 401)
                ->push($this->rows(2)),
        ]);

        $this->assertSame([2], $this->collect($this->client()->stations()));
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer new'));
    }

    public function test_403_is_reported_as_possible_geo_block_not_bad_credentials(): void
    {
        Http::fake([
            'ff.test/api/v1/oauth/*' => Http::response($this->tokenResponse()),
            'ff.test/api/v1/pfs*' => Http::response(['message' => 'Forbidden'], 403),
        ]);

        try {
            $this->collect($this->client()->stations());
            $this->fail('Expected exception');
        } catch (FuelFinderException $e) {
            $this->assertSame('forbidden', $e->errorCode);
            $this->assertStringContainsString('UK IP', $e->getMessage());
        }
    }

    public function test_bad_credentials_are_reported_with_upstream_message(): void
    {
        Http::fake(['ff.test/api/v1/oauth/*' => Http::response(['success' => false, 'message' => 'Invalid client_id or client_secret'], 401)]);

        try {
            $this->collect($this->client()->stations());
            $this->fail('Expected exception');
        } catch (FuelFinderException $e) {
            $this->assertSame('auth_failed', $e->errorCode);
            $this->assertStringContainsString('Invalid client_id or client_secret', $e->getMessage());
        }
    }

    public function test_missing_credentials_fail_before_any_request(): void
    {
        Http::fake();

        try {
            $this->collect($this->client(['client_id' => null])->stations());
            $this->fail('Expected exception');
        } catch (FuelFinderException $e) {
            $this->assertSame('missing_credentials', $e->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_wrapped_or_non_list_payload_is_rejected_loudly(): void
    {
        Http::fake([
            'ff.test/api/v1/oauth/*' => Http::response($this->tokenResponse()),
            'ff.test/api/v1/pfs*' => Http::response(['success' => true, 'data' => []]),
        ]);

        try {
            $this->collect($this->client()->stations());
            $this->fail('Expected exception');
        } catch (FuelFinderException $e) {
            $this->assertSame('unexpected_response', $e->errorCode);
        }
    }

    public function test_requests_are_spaced_out(): void
    {
        Http::fake([
            'ff.test/api/v1/oauth/*' => Http::response($this->tokenResponse()),
            'ff.test/api/v1/pfs*' => Http::sequence()->push($this->rows(500))->push($this->rows(1)),
        ]);

        $this->collect($this->client(['min_request_interval_ms' => 2100])->stations());

        // token -> batch 1 -> batch 2: two gaps enforced.
        Sleep::assertSleptTimes(2);
    }
}
