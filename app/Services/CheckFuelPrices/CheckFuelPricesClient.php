<?php

namespace App\Services\CheckFuelPrices;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * Client for CheckFuelPrices' public stations endpoint (no key).
 *
 * Seams handled here:
 *  - No published rate limit: we impose our own (spacing between requests
 *    plus an app-wide per-minute cap), honour 429/Retry-After, and cache each
 *    circle for 30 minutes as the provider's terms recommend.
 *  - Invalid parameters come back as 302 redirects: redirects are disabled
 *    and any 3xx is treated as a rejected request.
 *  - Undocumented response: the shape is validated strictly so drift fails
 *    loudly instead of silently producing wrong prices.
 *  - Results are sorted by distance and capped (20): we report whether a
 *    response hit the cap so the caller can search smaller circles.
 */
class CheckFuelPricesClient
{
    private const RATE_KEY = 'checkfuelprices:requests';

    private ?float $lastRequestAt = null;

    public int $requests = 0;

    public int $cacheHits = 0;

    /** @var list<int> HTTP statuses seen, for the rate-limit probe. */
    public array $statuses = [];

    public function __construct(private readonly array $config) {}

    /**
     * @return array{stations: list<array>, truncated: bool, from_cache: bool}
     */
    public function stationsNear(float $lat, float $lng, float $radiusMiles, string $fuelCode, bool $useCache = true): array
    {
        $query = [
            'lat' => round($lat, 5),
            'lng' => round($lng, 5),
            'fuel' => $fuelCode,
            'radius' => round($radiusMiles, 1),
            'limit' => (int) $this->config['max_results'],
        ];
        $key = 'checkfuelprices:'.md5(http_build_query($query));

        if ($useCache && is_array($cached = Cache::get($key))) {
            $this->cacheHits++;

            return $cached + ['from_cache' => true];
        }

        $json = $this->get($query);

        $result = [
            'stations' => $json['stations'],
            // Results are distance-sorted and capped: a full page means
            // there may be more (possibly cheaper) stations further out.
            'truncated' => count($json['stations']) >= (int) $this->config['max_results'],
        ];

        Cache::put($key, $result, now()->addMinutes((int) $this->config['cache_minutes']));

        return $result + ['from_cache' => false];
    }

    private function get(array $query): array
    {
        $maxRetries = (int) $this->config['max_retries'];

        for ($attempt = 1; ; $attempt++) {
            $this->respectLimits();

            try {
                $response = Http::baseUrl(rtrim($this->config['base_url'], '/'))
                    ->withoutRedirecting()
                    ->withUserAgent($this->config['user_agent'])
                    ->acceptJson()
                    ->timeout((int) $this->config['timeout_seconds'])
                    ->get($this->config['stations_path'], $query);
            } catch (ConnectionException $e) {
                if ($attempt <= $maxRetries) {
                    $this->backoff($attempt, null, 'connection error');

                    continue;
                }
                throw CheckFuelPricesException::unreachable($e);
            } finally {
                $this->lastRequestAt = microtime(true);
                $this->requests++;
            }

            $this->statuses[] = $response->status();

            if ($response->successful()) {
                return $this->validate($response);
            }

            if ($response->redirect()) {
                throw CheckFuelPricesException::rejected($response->status(), $response->header('Location') ?: null, $query);
            }

            if (($response->status() === 429 || $response->serverError()) && $attempt <= $maxRetries) {
                $this->backoff($attempt, $response->header('Retry-After'), "HTTP {$response->status()}");

                continue;
            }

            throw $response->status() === 429
                ? CheckFuelPricesException::rateLimited(is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null)
                : CheckFuelPricesException::httpError($response->status());
        }
    }

    private function validate(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            throw CheckFuelPricesException::unexpectedShape('body is not JSON: '.mb_strimwidth($response->body(), 0, 80, '...'));
        }
        if (($json['success'] ?? null) !== true) {
            throw CheckFuelPricesException::unexpectedShape('success flag is not true');
        }
        if (! isset($json['stations']) || ! is_array($json['stations']) || ! array_is_list($json['stations'])) {
            throw CheckFuelPricesException::unexpectedShape('missing "stations" list');
        }

        foreach ($json['stations'] as $i => $station) {
            foreach (['id', 'lat', 'lng', 'price'] as $field) {
                if (! is_array($station) || ! array_key_exists($field, $station)) {
                    throw CheckFuelPricesException::unexpectedShape("station #{$i} has no \"{$field}\"");
                }
            }
        }

        return $json;
    }

    /**
     * No official limit exists, so be a good citizen: a minimum gap between
     * requests (this process) and a per-minute cap (whole app, all users).
     */
    private function respectLimits(): void
    {
        $perMinute = (int) $this->config['max_requests_per_minute'];

        if (RateLimiter::tooManyAttempts(self::RATE_KEY, $perMinute)) {
            $wait = RateLimiter::availableIn(self::RATE_KEY);
            if ($wait > (int) $this->config['max_wait_seconds']) {
                throw CheckFuelPricesException::localBudgetExhausted($perMinute, $wait);
            }
            Sleep::for($wait)->seconds();
        }
        RateLimiter::hit(self::RATE_KEY, 60);

        if ($this->lastRequestAt !== null) {
            $gap = $this->lastRequestAt + ((int) $this->config['min_request_interval_ms']) / 1000 - microtime(true);
            if ($gap > 0) {
                Sleep::for((int) ceil($gap * 1000))->milliseconds();
            }
        }
    }

    private function backoff(int $attempt, ?string $retryAfter, string $reason): void
    {
        $seconds = is_numeric($retryAfter) ? min(30, (int) $retryAfter) : min(8, 2 ** $attempt);
        Log::warning("CheckFuelPrices: {$reason}; retrying in {$seconds}s (attempt {$attempt}).");
        Sleep::for($seconds)->seconds();
    }
}
