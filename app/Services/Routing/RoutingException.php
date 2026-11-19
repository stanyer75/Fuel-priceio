<?php

namespace App\Services\Routing;

use App\Exceptions\UpstreamException;
use Throwable;

class RoutingException extends UpstreamException
{
    public static function missingKey(): self
    {
        return new self('openrouteservice', 'missing_api_key',
            'No openrouteservice API key configured. Get a free key at openrouteservice.org and set ORS_API_KEY in .env.', 503);
    }

    public static function invalidKey(?string $upstream): self
    {
        return new self('openrouteservice', 'invalid_api_key',
            'openrouteservice rejected the API key ('.($upstream ?: 'no detail').'). Check ORS_API_KEY.', 503);
    }

    /** Seam: ORS uses 403 (not 429) when the DAILY quota is used up. */
    public static function quotaExhausted(?string $upstream): self
    {
        return new self('openrouteservice', 'quota_exhausted',
            'The daily openrouteservice quota for this API key is used up. It resets 24h after the first request of the day. ('.($upstream ?: '403').')', 503);
    }

    public static function rateLimited(): self
    {
        return new self('openrouteservice', 'rate_limited',
            'Too many routing requests this minute (openrouteservice per-minute limit). Please try again shortly.', 429);
    }

    public static function placeNotFound(string $query): self
    {
        return new self('openrouteservice', 'place_not_found', "Couldn't find a UK place matching \"{$query}\".", 422);
    }

    public static function noRoute(?string $upstream): self
    {
        return new self('openrouteservice', 'no_route',
            'No driving route found between those places (ferries are excluded). '.($upstream ?: ''), 422);
    }

    public static function httpError(int $status, string $path, ?string $upstream): self
    {
        return new self('openrouteservice', 'http_error', "openrouteservice returned HTTP {$status} for {$path}: ".($upstream ?: 'no detail'), 502);
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self('openrouteservice', 'unreachable', 'Could not connect to openrouteservice: '.$previous->getMessage(), 503, $previous);
    }

    public static function unexpectedShape(string $path, string $detail): self
    {
        return new self('openrouteservice', 'unexpected_response', "Unexpected response from openrouteservice {$path}: {$detail}", 502);
    }
}
