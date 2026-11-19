<?php

namespace App\Services\FuelFinder;

/**
 * Outcome of cleaning one raw record: either a row ready for the database
 * (possibly with corrections applied), or a rejection reason.
 */
final class NormalisedRecord
{
    /**
     * @param  array<string, mixed>|null  $row
     * @param  list<string>  $corrections  short slugs, e.g. "coordinates_swapped"
     */
    private function __construct(
        public readonly ?array $row,
        public readonly ?string $rejection,
        public readonly array $corrections,
    ) {}

    public static function accept(array $row, array $corrections = []): self
    {
        return new self($row, null, $corrections);
    }

    public static function reject(string $reason): self
    {
        return new self(null, $reason, []);
    }

    public function accepted(): bool
    {
        return $this->row !== null;
    }
}
