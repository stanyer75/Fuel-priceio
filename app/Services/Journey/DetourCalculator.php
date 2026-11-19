<?php

namespace App\Services\Journey;

use App\Support\Geo;

/**
 * The "is it worth it?" maths, kept pure so it is trivially testable.
 *
 * Total cost of using a station =
 *     cost of the fuel you buy there
 *   + cost of the extra fuel burned driving the detour to reach it.
 *
 * Simplifications (stated in the UI too):
 *  - detour fuel is priced at that station's price;
 *  - your time is not given a monetary value (extra minutes are shown instead);
 *  - MPG is UK (imperial) miles per gallon: 4.54609 litres per gallon.
 */
final class DetourCalculator
{
    public function __construct(
        public readonly float $mpg,
        public readonly float $litres,
    ) {}

    public function fillCost(float $pricePpl): float
    {
        return $this->litres * $pricePpl / 100;
    }

    public function detourLitres(float $detourMiles): float
    {
        return max(0.0, $detourMiles) / $this->mpg * Geo::LITRES_PER_UK_GALLON;
    }

    public function detourCost(float $detourMiles, float $pricePpl): float
    {
        return $this->detourLitres($detourMiles) * $pricePpl / 100;
    }

    public function totalCost(float $pricePpl, float $detourMiles): float
    {
        return $this->fillCost($pricePpl) + $this->detourCost($detourMiles, $pricePpl);
    }
}
