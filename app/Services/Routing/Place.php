<?php

namespace App\Services\Routing;

final class Place
{
    public function __construct(
        public readonly string $query,   // what the user typed
        public readonly string $label,   // what the geocoder matched (shown so users can spot "wrong Newport")
        public readonly float $lat,
        public readonly float $lon,
    ) {}

    public function toArray(): array
    {
        return ['query' => $this->query, 'label' => $this->label, 'lat' => $this->lat, 'lon' => $this->lon];
    }

    public static function fromArray(array $a): self
    {
        return new self($a['query'], $a['label'], (float) $a['lat'], (float) $a['lon']);
    }
}
