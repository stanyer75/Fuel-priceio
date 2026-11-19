<?php

namespace App\Services\FuelFinder;

use Carbon\CarbonInterface;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Client for the Fuel Finder "Information Recipient" API.
 *
 * Seams handled here:
 *  - Auth: OAuth2 client credentials -> bearer token (1h TTL), cached and
 *    refreshed early; one automatic re-auth if a data call returns 401.
 *  - Rate limits: 1 concurrent request per client. PHP is single threaded
 *    per process, and SyncService wraps every sync in a cache lock so two
 *    processes never call the API at once. We also space requests out.
 *  - Errors: 429 and 5xx are retried with backoff (Retry-After honoured);
 *    401/403/404 are never retried blindly.
 *  - Pagination: batch-number from 1, 500 per batch, no total count; a short
 *    or empty batch (or a 404 past the end) means "done". Hard cap on batches.
 */
class FuelFinderClient implements FuelFinderSource
{
    private const TOKEN_CACHE_KEY = 'fuel-finder:access-token';

    private ?float $lastRequestAt = null;

    private int $requests = 0;

    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'live';
    }

    public function requestCount(): int
    {
        return $this->requests;
    }

    public function stations(): iterable
    {
        return $this->paginate('/api/v1/pfs');
    }

    public function prices(?CarbonInterface $changedSince = null): iterable
    {
        // Docs: "YYYY-MM-DD HH:MM:SS" (space, no "T"). We assume UTC.
        $query = $changedSince
            ? ['effective-start-timestamp' => $changedSince->copy()->utc()->format('Y-m-d H:i:s')]
            : [];

        return $this->paginate('/api/v1/pfs/fuel-prices', $query);
    }

    private function paginate(string $path, array $query = []): Generator
    {
        $size = (int) $this->config['batch_size'];
        $max = (int) $this->config['max_batches'];

        for ($batch = 1; $batch <= $max; $batch++) {
            try {
                $rows = $this->getList($path, $query + ['batch-number' => $batch]);
            } catch (FuelFinderException $e) {
                // Some clients report a 404 for the batch after the last one.
                if ($e->errorCode === 'not_found' && $batch > 1) {
                    return;
                }
                throw $e;
            }

            if ($rows === []) {
                return;
            }

            yield $rows;

            if (count($rows) < $size) {
                return;
            }
        }

        throw FuelFinderException::tooManyBatches($path, $max);
    }

    /** GET a data endpoint that must return a JSON list. */
    private function getList(string $path, array $query): array
    {
        $maxRetries = (int) $this->config['max_retries'];
        $reauthenticated = false;
        $attempt = 0;

        while (true) {
            $attempt++;

            // Fetch the token BEFORE send(): token() makes its own (throttled)
            // request, and nesting it inside send() would skip the spacing.
            $token = $this->token();

            try {
                $response = $this->send(fn () => $this->http()->withToken($token)->get($path, $query));
            } catch (ConnectionException $e) {
                if ($attempt <= $maxRetries) {
                    $this->backoff($attempt, null, "connection error: {$e->getMessage()}");

                    continue;
                }
                throw FuelFinderException::unreachable($e);
            }

            $status = $response->status();

            if ($response->successful()) {
                $data = $response->json();

                if (! is_array($data) || ! array_is_list($data)) {
                    throw FuelFinderException::unexpectedShape($path, 'expected a JSON array, got '.mb_strimwidth($response->body(), 0, 120, '...'));
                }

                return $data;
            }

            if ($status === 401 && ! $reauthenticated) {
                // Token may have been revoked/expired early: get a fresh one once.
                Cache::forget(self::TOKEN_CACHE_KEY);
                $reauthenticated = true;

                continue;
            }

            if (($status === 429 || $status >= 500) && $attempt <= $maxRetries) {
                $this->backoff($attempt, $response->header('Retry-After'), "HTTP {$status}");

                continue;
            }

            throw match (true) {
                $status === 401 => FuelFinderException::authFailed($status, $this->upstreamMessage($response)),
                $status === 403 => FuelFinderException::forbidden($this->upstreamMessage($response)),
                $status === 404 => FuelFinderException::notFound($path),
                $status === 429 => FuelFinderException::rateLimited(),
                default => FuelFinderException::httpError($status, $path),
            };
        }
    }

    private function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (blank($this->config['client_id']) || blank($this->config['client_secret'])) {
            throw FuelFinderException::missingCredentials();
        }

        try {
            $response = $this->send(fn () => $this->http()->asJson()->post('/api/v1/oauth/generate_access_token', [
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
            ]));
        } catch (ConnectionException $e) {
            throw FuelFinderException::unreachable($e);
        }

        if (in_array($response->status(), [400, 401], true)) {
            throw FuelFinderException::authFailed($response->status(), $this->upstreamMessage($response));
        }
        if ($response->status() === 403) {
            throw FuelFinderException::forbidden($this->upstreamMessage($response));
        }
        if (! $response->successful()) {
            throw FuelFinderException::httpError($response->status(), '/api/v1/oauth/generate_access_token');
        }

        // Token responses ARE wrapped: {"success": true, "data": {...}}
        // whereas data endpoints return bare arrays.
        $token = $response->json('data.access_token');
        $expiresIn = (int) ($response->json('data.expires_in') ?? 3600);

        if (! is_string($token) || $token === '') {
            throw FuelFinderException::unexpectedShape('/api/v1/oauth/generate_access_token', 'no data.access_token in response');
        }

        $ttl = max(60, $expiresIn - (int) $this->config['token_expiry_margin_seconds']);
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    /** Space requests out so we stay well under the per-minute limit. */
    private function send(callable $request): Response
    {
        $interval = ((int) $this->config['min_request_interval_ms']) / 1000;

        if ($this->lastRequestAt !== null) {
            $wait = $this->lastRequestAt + $interval - microtime(true);
            if ($wait > 0) {
                Sleep::for((int) ceil($wait * 1000))->milliseconds();
            }
        }

        try {
            return $request();
        } finally {
            $this->lastRequestAt = microtime(true);
            $this->requests++;
        }
    }

    private function backoff(int $attempt, ?string $retryAfter, string $reason): void
    {
        $seconds = is_numeric($retryAfter) ? (int) $retryAfter : min(30, 2 ** $attempt);

        Log::warning("Fuel Finder: {$reason}; retrying in {$seconds}s (attempt {$attempt}).");
        Sleep::for($seconds)->seconds();
    }

    private function http()
    {
        return Http::baseUrl(rtrim($this->config['base_url'], '/'))
            ->acceptJson()
            ->timeout((int) $this->config['timeout_seconds']);
    }

    private function upstreamMessage(Response $response): ?string
    {
        $message = $response->json('message') ?? $response->json('error');

        return is_string($message) ? $message : null;
    }
}
