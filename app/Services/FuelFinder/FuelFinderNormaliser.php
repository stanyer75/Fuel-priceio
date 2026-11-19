<?php

namespace App\Services\FuelFinder;

use App\Enums\FuelType;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns raw Fuel Finder records into clean database rows.
 *
 * This is where the "data mismatch" seams live. Every fix is recorded as a
 * correction slug (and counted per sync run) rather than applied silently.
 */
class FuelFinderNormaliser
{
    /** Plausible UK pump prices in pence per litre. Outside this = reject. */
    public const MIN_PPL = 50.0;

    public const MAX_PPL = 350.0;

    public function station(array $raw): NormalisedRecord
    {
        $nodeId = trim((string) ($raw['node_id'] ?? ''));
        if ($nodeId === '') {
            return NormalisedRecord::reject('missing_node_id');
        }

        $location = is_array($raw['location'] ?? null) ? $raw['location'] : [];
        $corrections = [];

        $coordinates = $this->coordinates($location['latitude'] ?? null, $location['longitude'] ?? null);
        if ($coordinates === null) {
            return NormalisedRecord::reject('invalid_coordinates');
        }
        [$lat, $lon, $coordinateFix] = $coordinates;
        if ($coordinateFix) {
            $corrections[] = $coordinateFix;
        }

        $postcode = $this->postcode($location['postcode'] ?? null);
        if ($postcode !== null && $postcode !== ($location['postcode'] ?? null)) {
            $corrections[] = 'postcode_reformatted';
        }

        [$openingTimes, $hoursUnknown] = $this->openingTimes($raw['opening_times'] ?? null);
        if ($hoursUnknown) {
            $corrections[] = 'opening_hours_unknown';
        }

        return NormalisedRecord::accept([
            'node_id' => $nodeId,
            'trading_name' => $this->displayCase($this->text($raw['trading_name'] ?? null)) ?? 'Unnamed forecourt',
            'brand_name' => $this->displayCase($this->text($raw['brand_name'] ?? null)),
            'address_line_1' => $this->displayCase($this->text($location['address_line_1'] ?? null)),
            'address_line_2' => $this->displayCase($this->text($location['address_line_2'] ?? null)),
            'city' => $this->displayCase($this->text($location['city'] ?? null)),
            'county' => $this->displayCase($this->text($location['county'] ?? null)),
            'postcode' => $postcode,
            'latitude' => round($lat, 6),
            'longitude' => round($lon, 6),
            'is_motorway_service_station' => (bool) ($raw['is_motorway_service_station'] ?? false),
            'is_supermarket_service_station' => (bool) ($raw['is_supermarket_service_station'] ?? false),
            'temporary_closure' => (bool) ($raw['temporary_closure'] ?? false),
            // Seam: permanent_closure can be null; treat null as "not closed".
            'permanent_closure' => (bool) ($raw['permanent_closure'] ?? false),
            'opening_times' => $openingTimes === null ? null : json_encode($openingTimes),
            'amenities' => json_encode(array_values(array_filter((array) ($raw['amenities'] ?? []), 'is_string'))),
            'data_corrections' => $corrections === [] ? null : json_encode($corrections),
        ], $corrections);
    }

    /**
     * A raw price record is one station with a list of fuel prices, so it
     * can produce several rows (or none).
     *
     * @return list<NormalisedRecord>
     */
    public function prices(array $raw): array
    {
        $nodeId = trim((string) ($raw['node_id'] ?? ''));
        if ($nodeId === '') {
            return [NormalisedRecord::reject('missing_node_id')];
        }

        $results = [];
        foreach ((array) ($raw['fuel_prices'] ?? []) as $entry) {
            $results[] = is_array($entry) ? $this->price($nodeId, $entry) : NormalisedRecord::reject('malformed_price_entry');
        }

        return $results;
    }

    public function price(string $nodeId, array $entry): NormalisedRecord
    {
        $fuel = FuelType::fromFeed($entry['fuel_type'] ?? null);
        if ($fuel === null) {
            return NormalisedRecord::reject('unknown_fuel_type');
        }

        $corrections = [];
        if (($entry['fuel_type'] ?? null) !== $fuel->value) {
            $corrections[] = 'fuel_code_mapped';
        }

        // Seam: price is a zero-padded STRING in pence ("0120.0000"), may be
        // null (registered but not priced), and is occasionally sent in POUNDS
        // ("1.429"). A plain number is also accepted.
        $rawPrice = $entry['price'] ?? null;
        if ($rawPrice === null || $rawPrice === '' || ! is_numeric($rawPrice)) {
            return NormalisedRecord::reject('no_price');
        }

        $ppl = (float) $rawPrice;
        if ($ppl > 0.5 && $ppl < 3.5) {
            $ppl *= 100;
            $corrections[] = 'price_converted_from_pounds';
        }

        if ($ppl < self::MIN_PPL || $ppl > self::MAX_PPL) {
            return NormalisedRecord::reject('implausible_price');
        }

        $updatedAt = $this->timestamp($entry['price_last_updated'] ?? $entry['price_change_effective_timestamp'] ?? null);
        if ($updatedAt === null) {
            $corrections[] = 'missing_price_timestamp';
        }

        return NormalisedRecord::accept([
            'node_id' => $nodeId,
            'fuel_type' => $fuel->value,
            'price_ppl' => round($ppl, 1),
            'price_updated_at' => $updatedAt?->format('Y-m-d H:i:s'),
            'data_corrections' => $corrections === [] ? null : json_encode($corrections),
        ], $corrections);
    }

    /**
     * Validates a coordinate pair against a UK bounding box and repairs the
     * two errors seen in the wild: lat/lon swapped, and a positive longitude
     * that should be negative (west of Greenwich). A repair is only accepted
     * if it lands inside the UK.
     *
     * Known limitation: a sign error east of ~1.8W (e.g. -0.5 sent as 0.5)
     * still lands inside the UK box and cannot be detected here.
     *
     * @return array{0: float, 1: float, 2: ?string}|null [lat, lon, correction]
     */
    public function coordinates(mixed $latitude, mixed $longitude): ?array
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $lat = (float) $latitude;
        $lon = (float) $longitude;

        if ($lat == 0.0 && $lon == 0.0) {
            return null;
        }

        $candidates = [
            [$lat, $lon, null],
            [$lon, $lat, 'coordinates_swapped'],
            [$lat, -$lon, 'longitude_sign_flipped'],
            [$lon, -$lat, 'coordinates_swapped_and_sign_flipped'],
        ];

        foreach ($candidates as [$tryLat, $tryLon, $fix]) {
            if (Geo::inUk($tryLat, $tryLon)) {
                return [$tryLat, $tryLon, $fix];
            }
        }

        return null;
    }

    /** "ll651tq" / "LL65 1TQ " -> "LL65 1TQ" (the inward code is always the last 3 chars). */
    public function postcode(mixed $postcode): ?string
    {
        $compact = strtoupper(preg_replace('/\s+/', '', (string) $postcode));

        if (strlen($compact) < 5 || strlen($compact) > 7) {
            return $compact === '' ? null : $compact;
        }

        return substr($compact, 0, -3).' '.substr($compact, -3);
    }

    /**
     * Accepts every timestamp format seen across Fuel Finder outputs:
     *  - API:  "2025-12-31T08:15:23" (no timezone; treated as UTC)
     *  - API query format / some fields: "2026-03-07 15:34:42"
     *  - CSV:  "Sat Mar 07 2026 15:34:42 GMT+0000 (Coordinated Universal Time)"
     */
    public function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        // Strip the JavaScript-style "(Coordinated Universal Time)" suffix.
        $value = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $value));

        try {
            if (preg_match('/^[A-Z][a-z]{2} [A-Z][a-z]{2} \d{2} \d{4} \d{2}:\d{2}:\d{2} GMT[+-]\d{4}$/', $value)) {
                return CarbonImmutable::createFromFormat('D M d Y H:i:s \G\M\TO', $value)->utc();
            }

            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Seam: "00:00:00"-"00:00:00" with is_24_hours=false means the operator
     * didn't fill the hours in, NOT that the site is open all day.
     *
     * @return array{0: ?array, 1: bool} [cleaned opening times, any unknown days]
     */
    private function openingTimes(mixed $raw): array
    {
        if (! is_array($raw) || ! is_array($raw['usual_days'] ?? null)) {
            return [null, true];
        }

        $unknown = false;
        $days = [];

        foreach ($raw['usual_days'] as $day => $hours) {
            if (! is_array($hours)) {
                continue;
            }

            $is24 = (bool) ($hours['is_24_hours'] ?? false);
            $open = $hours['open'] ?? $hours['open_time'] ?? null;
            $close = $hours['close'] ?? $hours['close_time'] ?? null;

            if (! $is24 && (($open === null && $close === null) || ($open === '00:00:00' && $close === '00:00:00'))) {
                $days[strtolower((string) $day)] = null;
                $unknown = true;

                continue;
            }

            $days[strtolower((string) $day)] = ['open' => $open, 'close' => $close, 'is_24_hours' => $is24];
        }

        return [$days, $unknown];
    }

    /**
     * Seam: text fields often arrive in ALL CAPS ("DEMO FUELS LLANDUDNO").
     * Title-case those for display, but leave mixed-case text alone (it was
     * typed deliberately) and keep known acronyms upper case.
     */
    public function displayCase(?string $value): ?string
    {
        if ($value === null || $value !== mb_strtoupper($value) || ! preg_match('/[A-Z]{2}/', $value)) {
            return $value;
        }

        $acronyms = ['BP', 'MFG', 'SGN', 'JET', 'UK', 'HGV', 'LPG', 'EV', 'MSA', 'A55', 'M56'];

        return preg_replace_callback('/[A-Za-z0-9]+/', function ($m) use ($acronyms) {
            return in_array($m[0], $acronyms, true) ? $m[0] : mb_convert_case(mb_strtolower($m[0]), MB_CASE_TITLE);
        }, $value);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value));

        return $value === '' ? null : $value;
    }
}
