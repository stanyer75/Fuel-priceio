<?php

namespace App\Services\Routing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * openrouteservice: geocoding, directions and the distance matrix.
 *
 * Seams handled here:
 *  - Coordinate order: ORS speaks GeoJSON [lon, lat]. This class converts at
 *    the boundary; everything it returns is [lat, lon].
 *  - Errors: 403 means EITHER a bad key OR the daily quota is exhausted (we
 *    tell them apart by message); 429 is the per-minute limit (retried once).
 *  - Quota visibility: x-ratelimit-remaining is captured for /api responses.
 *  - Caching: geocodes (7 days) and routes (24h) are cached so repeat
 *    searches do not spend quota. The matrix is not cached here because the
 *    whole search result is cached for 5 minutes by JourneyPlanner.
 */
class OpenRouteServiceClient
{
    public ?int $quotaRemaining = null;

    public function __construct(private readonly array $config) {}

    public function geocode(string $text): Place
    {
        $text = trim($text);
        $key = 'ors:geocode:'.md5(mb_strtolower($text));

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return Place::fromArray($cached);
        }

        $json = $this->request('get', $this->config['geocode_path'], [
            'text' => $text,
            'boundary.country' => 'GB',
            'size' => 1,
        ]);

        $feature = $json['features'][0] ?? null;
        $coordinates = $feature['geometry']['coordinates'] ?? null;

        if (! is_array($coordinates) || count($coordinates) < 2) {
            throw RoutingException::placeNotFound($text);
        }

        $place = new Place(
            query: $text,
            label: (string) ($feature['properties']['label'] ?? $text),
            lat: (float) $coordinates[1],   // GeoJSON is [lon, lat]
            lon: (float) $coordinates[0],
        );

        Cache::put($key, $place->toArray(), now()->addDays((int) config('fueldetour.journey.geocode_cache_days')));

        return $place;
    }

    /**
     * @return array{distance_m: float, duration_s: float, geometry: list<array{0: float, 1: float}>}
     */
    public function route(Place $from, Place $to): array
    {
        $key = sprintf('ors:route:%.5f,%.5f:%.5f,%.5f', $from->lat, $from->lon, $to->lat, $to->lon);

        return Cache::remember($key, now()->addHours((int) config('fueldetour.journey.route_cache_hours')), function () use ($from, $to) {
            $path = "{$this->config['routing_prefix']}/v2/directions/{$this->config['profile']}/geojson";
            $json = $this->request('post', $path, [
                'coordinates' => [[$from->lon, $from->lat], [$to->lon, $to->lat]],
                'instructions' => false,
                // Holyhead is a ferry port: without this, "Holyhead to Dublin"
                // would happily route across the Irish Sea.
                'options' => ['avoid_features' => ['ferries']],
            ], accept: 'application/geo+json'); // seam: /geojson returns 406 for Accept: application/json

            $feature = $json['features'][0] ?? null;
            $line = $feature['geometry']['coordinates'] ?? null;
            if (! is_array($line) || count($line) < 2) {
                throw RoutingException::unexpectedShape($path, 'no route geometry');
            }

            return [
                'distance_m' => (float) ($feature['properties']['summary']['distance'] ?? 0),
                'duration_s' => (float) ($feature['properties']['summary']['duration'] ?? 0),
                'geometry' => array_map(fn ($p) => [(float) $p[1], (float) $p[0]], $line),
            ];
        });
    }

    /**
     * Road distances/durations between points.
     *
     * @param  list<array{0: float, 1: float}>  $locations  [lat, lon]
     * @return array{distances: array, durations: array} rows = sources, cols = destinations; null = unroutable
     */
    public function matrix(array $locations, array $sources, array $destinations): array
    {
        $path = "{$this->config['routing_prefix']}/v2/matrix/{$this->config['profile']}";
        $json = $this->request('post', $path, [
            'locations' => array_map(fn ($p) => [$p[1], $p[0]], $locations),
            'sources' => $sources,
            'destinations' => $destinations,
            'metrics' => ['distance', 'duration'],
            'units' => 'm',
        ]);

        if (! isset($json['distances'], $json['durations']) || ! is_array($json['distances'])) {
            throw RoutingException::unexpectedShape($path, 'missing distances/durations');
        }

        return ['distances' => $json['distances'], 'durations' => $json['durations']];
    }

    private function request(string $method, string $path, array $payload, string $accept = 'application/json'): array
    {
        if (blank($this->config['api_key'])) {
            throw RoutingException::missingKey();
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                $http = Http::baseUrl(rtrim($this->config['base_url'], '/'))
                    ->withHeaders(['Authorization' => $this->config['api_key']])
                    ->accept($accept)
                    ->timeout((int) $this->config['timeout_seconds']);

                $response = $method === 'get' ? $http->get($path, $payload) : $http->asJson()->post($path, $payload);
            } catch (ConnectionException $e) {
                throw RoutingException::unreachable($e);
            }

            $this->captureQuota($response);

            if ($response->successful()) {
                $json = $response->json();
                if (! is_array($json)) {
                    throw RoutingException::unexpectedShape($path, 'body was not JSON');
                }

                return $json;
            }

            // Per-minute limit: one short, polite retry, then give up.
            if ($response->status() === 429 && $attempt === 1) {
                $wait = min(5, max(1, (int) ($response->header('Retry-After') ?: 2)));
                Log::warning("openrouteservice 429 on {$path}; retrying in {$wait}s");
                Sleep::for($wait)->seconds();

                continue;
            }

            throw $this->translate($response, $path);
        }
    }

    private function translate(Response $response, string $path): RoutingException
    {
        $status = $response->status();
        $message = $this->upstreamMessage($response);
        $orsCode = (int) $response->json('error.code');

        return match (true) {
            $status === 401 => RoutingException::invalidKey($message),
            $status === 403 && str_contains(strtolower((string) $message), 'quota') => RoutingException::quotaExhausted($message),
            $status === 403 => RoutingException::invalidKey($message),
            $status === 429 => RoutingException::rateLimited(),
            // ORS directions/matrix error codes: 2004 route too long,
            // 2009 route not found, 2010 point not routable.
            in_array($orsCode, [2004, 2009, 2010, 6004, 6010], true) => RoutingException::noRoute($message),
            default => RoutingException::httpError($status, $path, $message),
        };
    }

    private function captureQuota(Response $response): void
    {
        $remaining = $response->header('x-ratelimit-remaining');
        if (is_numeric($remaining)) {
            $this->quotaRemaining = (int) $remaining;
        }
    }

    private function upstreamMessage(Response $response): ?string
    {
        $message = $response->json('error.message') ?? $response->json('error') ?? $response->json('message');

        return is_string($message) ? $message : (is_null($message) ? null : json_encode($message));
    }
}
