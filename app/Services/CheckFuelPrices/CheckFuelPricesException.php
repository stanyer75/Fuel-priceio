<?php

namespace App\Services\CheckFuelPrices;

use App\Exceptions\UpstreamException;
use Throwable;

class CheckFuelPricesException extends UpstreamException
{
    /**
     * Seam: invalid parameters (radius < 1 or > 50, limit > 20, unknown
     * sort) are answered with a 302 redirect - not a 400 - presumably to an
     * HTML page. Following it would hand us a web page with HTTP 200.
     */
    public static function rejected(int $status, ?string $location, array $query): self
    {
        return new self('checkfuelprices', 'request_rejected', sprintf(
            'CheckFuelPrices rejected the request with HTTP %d%s (usually an out-of-range parameter: %s).',
            $status, $location ? " redirect to {$location}" : '', http_build_query($query)), 502);
    }

    public static function rateLimited(?int $retryAfter): self
    {
        return new self('checkfuelprices', 'rate_limited',
            'CheckFuelPrices is rate limiting us (HTTP 429'.($retryAfter ? ", retry after {$retryAfter}s" : '').'). Try again shortly.', 503);
    }

    /** Our own politeness cap was hit (the provider publishes no limit, so we set one). */
    public static function localBudgetExhausted(int $perMinute, int $waitSeconds): self
    {
        return new self('checkfuelprices', 'local_rate_cap',
            "This app limits itself to {$perMinute} CheckFuelPrices requests a minute (no official limit is published). Try again in {$waitSeconds}s.", 503);
    }

    public static function httpError(int $status): self
    {
        return new self('checkfuelprices', 'http_error', "CheckFuelPrices returned HTTP {$status}.", 502);
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self('checkfuelprices', 'unreachable', 'Could not connect to CheckFuelPrices: '.$previous->getMessage(), 503, $previous);
    }

    public static function unexpectedShape(string $detail): self
    {
        return new self('checkfuelprices', 'unexpected_response',
            "CheckFuelPrices returned an unexpected response ({$detail}). The endpoint is undocumented and may have changed.", 502);
    }
}
