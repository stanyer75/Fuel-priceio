<?php

namespace App\Enums;

/**
 * Canonical fuel types used inside this app.
 *
 * Seam: the same fuel has different codes depending on where it came from:
 *  - Fuel Finder API:            E10, E5, B7_STANDARD, B7_PREMIUM, B10, HVO
 *  - Fuel Finder CSV download:   E10, E5, B7S, B7P, B10, HVO
 *  - Old CMA interim JSON feeds: E10, E5, B7, SDV (super diesel)
 * fromFeed() maps all of them onto one enum.
 */
enum FuelType: string
{
    case E10 = 'E10';
    case E5 = 'E5';
    case B7Standard = 'B7_STANDARD';
    case B7Premium = 'B7_PREMIUM';
    case B10 = 'B10';
    case HVO = 'HVO';

    public static function fromFeed(?string $code): ?self
    {
        $code = strtoupper(trim((string) $code));

        return match ($code) {
            'E10' => self::E10,
            'E5' => self::E5,
            'B7_STANDARD', 'B7S', 'B7' => self::B7Standard,
            'B7_PREMIUM', 'B7P', 'SDV' => self::B7Premium,
            'B10' => self::B10,
            'HVO' => self::HVO,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::E10 => 'Unleaded petrol (E10)',
            self::E5 => 'Super unleaded petrol (E5)',
            self::B7Standard => 'Diesel (B7)',
            self::B7Premium => 'Premium diesel',
            self::B10 => 'Diesel (B10)',
            self::HVO => 'HVO renewable diesel',
        };
    }

    /** Fuel types offered in the search form. */
    public static function searchable(): array
    {
        return [self::E10, self::E5, self::B7Standard, self::B7Premium];
    }
}
