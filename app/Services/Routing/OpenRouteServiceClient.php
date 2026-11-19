<?php

namespace App\Services\Routing;

use App\Support\Geo;
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

        // Seams found in live testing:
        //  - Restricted to GB, "Dublin" matched a venue "Dublin - Liverpool"
        //    (Bootle) with confidence 1.0 and match_type "exact".
        //  - match_type is unreliable: the CORRECT Belfast and Brighton come
        //    back as "fallback", the WRONG "Newport Community" as "exact".
        // So we trust the place NAME: it must equal what was typed (before
        // any comma); text after the comma ("Newport, Wales") must appear in
        // the label. Real places are preferred; venues ("Manchester Airport")
        // are only used when no place has that name.
        [$name, $qualifier] = array_map('trim', array_pad(explode(',', $text, 2), 2, ''));
        $candidates = $this->namedMatches($text, self::PLACE_LAYERS, $name, $qualifier)
            ?: $this->namedMatches($text, 'venue', $name, $qualifier);

        if ($candidates === []) {
            throw RoutingException::placeNotFound($text);
        }

        // Same name, far apart ("Newport": Isle of Wight, Shropshire or
        // Wales?) means we'd be guessing: ask the user instead.
        $first = $candidates[0];
        $elsewhere = array_values(array_filter($candidates, fn (Place $p) => Geo::distance($first->lat, $first->lon, $p->lat, $p->lon) > 15000));
        if ($elsewhere !== []) {
            $labels = array_values(array_unique(array_map(fn (Place $p) => $p->label, [$first, ...$elsewhere])));
            throw RoutingException::ambiguousPlace($text, array_slice($labels, 0, 4));
        }

        $place = $first;

        Cache::put($key, $place->toArray(), now()->addDays((int) config('fueldetour.journey.geocode_cache_days')));

        return $place;
    }

    /** Pelias layers that are real places rather than points of interest. */
    private const PLACE_LAYERS = 'locality,localadmin,borough,neighbourhood,county,macrocounty,region,postalcode,address,street';

    /**
     * Results whose name is what the user typed ("Dublin Road" is not
     * "Dublin"), optionally narrowed by a qualifier ("Wales").
     *
     * @return list<Place>
     */
    private function namedMatches(string $text, string $layers, string $name, string $qualifier): array
    {
        $json = $this->request('get', $this->config['geocode_path'], [
            'text' => $text,
            'boundary.country' => 'GB',
            'layers' => $layers,
            'size' => 10,
        ]);

        // Compare case- and space-insensitively so "ll651dq" matches "LL65 1DQ".
        $normalise = fn (?string $s) => preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $s));

        $places = [];
        foreach ($json['features'] ?? [] as $feature) {
            $coordinates = $feature['geometry']['coordinates'] ?? null;
            if (! is_array($coordinates) || count($coordinates) < 2) {
                continue;
            }

            // Some places carry alternative names: "Londonderry / Derry".
            $properties = $feature['properties'] ?? [];
            $names = array_map($normalise, explode('/', (string) ($properties['name'] ?? '')));
            if (! in_array($normalise($name), $names, true)) {
                continue;
            }
            if ($qualifier !== '' && ! str_contains(mb_strtolower((string) ($properties['label'] ?? '')), mb_strtolower($qualifier))) {
                continue;
            }

            $places[] = new Place(
                query: $text,
                label: (string) ($feature['properties']['label'] ?? $text),
                lat: (float) $coordinates[1],   // GeoJSON is [lon, lat]
                lon: (float) $coordinates[0],
            );
        }

        return $places;
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
