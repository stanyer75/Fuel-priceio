<?php

namespace Tests\Unit;

use App\Enums\FuelType;
use App\Services\FuelFinder\FuelFinderNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The data-mismatch seams: every repair and rejection the feed needs. */
class FuelFinderNormaliserTest extends TestCase
{
    private FuelFinderNormaliser $n;

    protected function setUp(): void
    {
        $this->n = new FuelFinderNormaliser;
    }

    public function test_valid_uk_coordinates_pass_through(): void
    {
        $this->assertSame([53.3065, -4.628, null], $this->n->coordinates('53.3065', '-4.628'));
    }

    public function test_swapped_coordinates_are_repaired(): void
    {
        $this->assertSame([53.286, -3.58, 'coordinates_swapped'], $this->n->coordinates('-3.58', '53.286'));
    }

    public function test_wrong_longitude_sign_is_repaired(): void
    {
        $this->assertSame([53.262, -3.44, 'longitude_sign_flipped'], $this->n->coordinates('53.262', '3.44'));
    }

    #[DataProvider('unusableCoordinates')]
    public function test_unrepairable_coordinates_are_rejected(mixed $lat, mixed $lon): void
    {
        $this->assertNull($this->n->coordinates($lat, $lon));
    }

    public static function unusableCoordinates(): array
    {
        return [
            'zero island' => [0, 0],
            'null' => [null, null],
            'non numeric' => ['abc', '-2.2'],
            'paris' => ['48.85', '2.35'],
        ];
    }

    #[DataProvider('postcodes')]
    public function test_postcodes_are_normalised(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, $this->n->postcode($raw));
    }

    public static function postcodes(): array
    {
        return [
            'already fine' => ['LL65 1DQ', 'LL65 1DQ'],
            'lower, no space' => ['ll653ex', 'LL65 3EX'],
            'short' => ['m11aa', 'M1 1AA'],
            'extra spaces' => [' WA15  8AA ', 'WA15 8AA'],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('prices')]
    public function test_price_parsing(mixed $raw, ?float $expectedPpl, ?string $expectedRejection, ?string $expectedCorrection): void
    {
        $result = $this->n->price('node', ['fuel_type' => 'E10', 'price' => $raw, 'price_last_updated' => '2026-11-18T07:00:00']);

        $this->assertSame($expectedRejection, $result->rejection);
        $this->assertSame($expectedPpl, $result->row['price_ppl'] ?? null);
        if ($expectedCorrection) {
            $this->assertContains($expectedCorrection, $result->corrections);
        }
    }

    public static function prices(): array
    {
        return [
            'zero padded pence string' => ['0120.0000', 120.0, null, null],
            'plain number' => [139.9, 139.9, null, null],
            'pounds' => ['1.429', 142.9, null, 'price_converted_from_pounds'],
            'null (registered, unpriced)' => [null, null, 'no_price', null],
            'empty string' => ['', null, 'no_price', null],
            'implausibly low' => ['0009.9900', null, 'implausible_price', null],
            'implausibly high' => ['0999.0000', null, 'implausible_price', null],
        ];
    }

    #[DataProvider('fuelCodes')]
    public function test_fuel_codes_from_every_source_map_to_one_enum(string $code, ?FuelType $expected): void
    {
        $this->assertSame($expected, FuelType::fromFeed($code));
    }

    public static function fuelCodes(): array
    {
        return [
            'api diesel' => ['B7_STANDARD', FuelType::B7Standard],
            'csv diesel' => ['B7S', FuelType::B7Standard],
            'cma interim diesel' => ['B7', FuelType::B7Standard],
            'csv premium diesel' => ['B7P', FuelType::B7Premium],
            'cma super diesel' => ['SDV', FuelType::B7Premium],
            'lower case' => ['e10', FuelType::E10],
            'unknown' => ['LPG', null],
        ];
    }

    #[DataProvider('timestamps')]
    public function test_every_timestamp_format_parses_to_utc(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->n->timestamp($raw)?->format('Y-m-d H:i:s'));
    }

    public static function timestamps(): array
    {
        return [
            'api iso without zone' => ['2025-12-31T08:15:23', '2025-12-31 08:15:23'],
            'space separated' => ['2026-03-07 15:34:42', '2026-03-07 15:34:42'],
            'csv javascript style' => ['Sat Mar 07 2026 15:34:42 GMT+0000 (Coordinated Universal Time)', '2026-03-07 15:34:42'],
            'javascript style with offset' => ['Sat Mar 07 2026 16:34:42 GMT+0100 (British Summer Time)', '2026-03-07 15:34:42'],
        ];
    }

    #[DataProvider('names')]
    public function test_all_caps_names_are_made_readable(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->n->displayCase($raw));
    }

    public static function names(): array
    {
        return [
            'all caps' => ['DEMO FUELS LLANDUDNO', 'Demo Fuels Llandudno'],
            'acronym kept' => ['BP CONNECT ST ASAPH', 'BP Connect St Asaph'],
            'hyphen and apostrophe' => ["STOCKTON-ON-TEES O'BRIEN", "Stockton-On-Tees O'Brien"],
            'mixed case untouched' => ['Tesco Extra', 'Tesco Extra'],
        ];
    }

    public function test_garbage_timestamp_is_null_not_an_exception(): void
    {
        $this->assertNull($this->n->timestamp('not a date'));
    }

    public function test_station_with_unfilled_opening_hours_is_flagged_not_treated_as_24h(): void
    {
        $raw = $this->station(['opening_times' => ['usual_days' => [
            'monday' => ['open' => '00:00:00', 'close' => '00:00:00', 'is_24_hours' => false],
            'tuesday' => ['open' => '00:00:00', 'close' => '00:00:00', 'is_24_hours' => true],
        ]]]);

        $result = $this->n->station($raw);
        $hours = json_decode($result->row['opening_times'], true);

        $this->assertContains('opening_hours_unknown', $result->corrections);
        $this->assertNull($hours['monday']);
        $this->assertTrue($hours['tuesday']['is_24_hours']);
    }

    public function test_null_permanent_closure_means_open(): void
    {
        $result = $this->n->station($this->station(['permanent_closure' => null]));

        $this->assertFalse($result->row['permanent_closure']);
    }

    public function test_station_without_node_id_is_rejected(): void
    {
        $this->assertSame('missing_node_id', $this->n->station($this->station(['node_id' => '']))->rejection);
    }

    private function station(array $overrides = []): array
    {
        return array_replace([
            'node_id' => 'abc',
            'trading_name' => 'TEST',
            'location' => ['latitude' => '53.3', 'longitude' => '-2.2', 'postcode' => 'M1 1AA'],
            'opening_times' => ['usual_days' => ['monday' => ['open' => '06:00:00', 'close' => '22:00:00', 'is_24_hours' => false]]],
        ], $overrides);
    }
}
