<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A failure talking to (or caused by) one of the upstream APIs.
 *
 * Every upstream error is translated into one of these so the UI and the
 * JSON API can tell the user WHICH service failed, WHY (a stable
 * machine-readable code), and what they can do about it.
 */
class UpstreamException extends RuntimeException
{
    public function __construct(
        public readonly string $source,     // "fuel_finder" | "openrouteservice" | "app"
        public readonly string $errorCode,  // stable slug, e.g. "quota_exhausted"
        string $message,                    // safe to show to end users
        public readonly int $status = 502,  // HTTP status our own API responds with
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ];
    }
}
