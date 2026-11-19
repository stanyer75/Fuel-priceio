<?php

namespace App\Services\FuelFinder;

use App\Exceptions\UpstreamException;
use Throwable;

class FuelFinderException extends UpstreamException
{
    public static function missingCredentials(): self
    {
        return new self('fuel_finder', 'missing_credentials',
            'Fuel Finder credentials are not configured. Set FUEL_FINDER_CLIENT_ID and FUEL_FINDER_CLIENT_SECRET, or use FUEL_FINDER_DRIVER=fixture.', 503);
    }

    public static function authFailed(int $status, ?string $upstreamMessage): self
    {
        return new self('fuel_finder', 'auth_failed',
            "Fuel Finder rejected our credentials (HTTP {$status}: ".($upstreamMessage ?: 'no message').').', 503);
    }

    /**
     * Seam: a 403 here is NOT necessarily bad credentials. Community reports
     * say the API refuses non-UK IP addresses with 403.
     */
    public static function forbidden(?string $upstreamMessage): self
    {
        return new self('fuel_finder', 'forbidden',
            'Fuel Finder returned 403 Forbidden. If credentials are valid, check the server has a UK IP address (non-UK IPs are reportedly blocked). Upstream said: '.($upstreamMessage ?: 'nothing'), 503);
    }

    public static function notFound(string $path): self
    {
        return new self('fuel_finder', 'not_found', "Fuel Finder returned 404 for {$path}.", 502);
    }

    public static function rateLimited(): self
    {
        return new self('fuel_finder', 'rate_limited',
            'Fuel Finder kept returning 429 (rate limit, or a previous request still in flight) after retries.', 503);
    }

    public static function httpError(int $status, string $path): self
    {
        return new self('fuel_finder', 'http_error', "Fuel Finder returned HTTP {$status} for {$path}.", 502);
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self('fuel_finder', 'unreachable', 'Could not connect to Fuel Finder: '.$previous->getMessage(), 503, $previous);
    }

    public static function unexpectedShape(string $path, string $detail): self
    {
        return new self('fuel_finder', 'unexpected_response', "Fuel Finder returned an unexpected response shape for {$path}: {$detail}", 502);
    }

    public static function tooManyBatches(string $path, int $max): self
    {
        return new self('fuel_finder', 'pagination_runaway',
            "Stopped paging {$path} after {$max} full batches (safety cap). The dataset may have grown; raise fueldetour.fuel_finder.max_batches.", 502);
    }
}
