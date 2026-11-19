<?php

namespace App\Services\Journey;

use App\Enums\FuelType;

final class JourneyQuery
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly FuelType $fuel,
        public readonly float $mpg,
        public readonly float $litres,
        public readonly float $corridorMiles,
    ) {}

    public static function fromValidated(array $v): self
    {
        $defaults = config('fueldetour.journey');

        return new self(
            from: trim($v['from']),
            to: trim($v['to']),
            fuel: FuelType::from($v['fuel'] ?? FuelType::E10->value),
            mpg: (float) ($v['mpg'] ?? $defaults['default_mpg']),
            litres: (float) ($v['litres'] ?? $defaults['default_litres']),
            corridorMiles: (float) ($v['corridor_miles'] ?? $defaults['default_corridor_miles']),
        );
    }

    public function cacheKey(): string
    {
        return md5(json_encode([
            mb_strtolower($this->from), mb_strtolower($this->to), $this->fuel->value,
            $this->mpg, $this->litres, $this->corridorMiles,
        ]));
    }
}
