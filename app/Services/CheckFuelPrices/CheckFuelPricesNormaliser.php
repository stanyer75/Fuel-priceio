<?php

namespace App\Services\CheckFuelPrices;

use App\Enums\FuelType;
use App\Services\FuelFinder\FuelFinderNormaliser;
use App\Services\FuelFinder\NormalisedRecord;
use Carbon\CarbonImmutable;

/**
 * Turns one CheckFuelPrices station into our normalised station shape.
 *
 * Reuses the Fuel Finder normaliser's coordinate, postcode, price and
 * timestamp rules: the data originates from the same government feed and
 * shows the same defects, plus a few of its own:
 *  - brand doubles as the display name and is inconsistent
 *    ("TESCO", "Jet", "ASDA LLANDUDNO SUPERSTORE");
 *  - two timestamp formats in one response;
 *  - leftover prices from the closed CMA scheme (e.g. dated April 2026),
 *    which the price-age rule then excludes;
 *  - no motorway/supermarket/closure flags at all.
 */
class CheckFuelPricesNormaliser
{
    public function __construct(private readonly FuelFinderNormaliser $base) {}

    /** Our fuel enum -> the provider's fuel codes (verified by probe). */
    public static function fuelCode(FuelType $fuel): string
    {
        return match ($fuel) {
            FuelType::E10 => 'E10',
            FuelType::E5 => 'E5',
            FuelType::B7Standard => 'B7',
            FuelType::B7Premium => 'SDV',
            default => throw new \InvalidArgumentException("{$fuel->value} is not offered by CheckFuelPrices"),
        };
    }

    public function station(array $raw, FuelType $fuel): NormalisedRecord
    {
        $id = trim((string) ($raw['id'] ?? ''));
        if ($id === '') {
            return NormalisedRecord::reject('missing_id');
        }

        $coordinates = $this->base->coordinates($raw['lat'] ?? null, $raw['lng'] ?? null);
        if ($coordinates === null) {
            return NormalisedRecord::reject('invalid_coordinates');
        }
        [$lat, $lon, $coordinateFix] = $coordinates;

        $nodeId = "cfp:{$id}";
        $price = $this->base->price($nodeId, [
            'fuel_type' => $fuel->value,
            'price' => $raw['price'] ?? null,
            'price_last_updated' => $raw['price_updated'] ?? null,
        ]);
        if (! $price->accepted()) {
            return NormalisedRecord::reject($price->rejection);
        }

        $corrections = array_values(array_filter([$coordinateFix, ...$price->corrections]));
        $brand = $this->base->displayCase(is_string($raw['brand'] ?? null) ? trim($raw['brand']) : null);
        $address = $this->base->displayCase(is_string($raw['address'] ?? null) ? trim($raw['address']) : null);

        return NormalisedRecord::accept([
            'node_id' => $nodeId,
            'name' => $brand ?: 'Unnamed forecourt',
            'brand' => $brand,
            'address' => $address,
            'postcode' => $this->base->postcode($raw['postcode'] ?? null),
            'lat' => $lat,
            'lon' => $lon,
            'is_motorway_services' => false,   // not provided by this source
            'is_supermarket' => false,         // not provided by this source
            'temporary_closure' => false,      // not provided by this source
            'price_ppl' => $price->row['price_ppl'],
            'price_updated_at' => $price->row['price_updated_at']
                ? CarbonImmutable::parse($price->row['price_updated_at'], 'UTC')
                : null,
            'data_corrections' => $corrections,
        ], $corrections);
    }
}
