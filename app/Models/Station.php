<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_motorway_service_station' => 'boolean',
            'is_supermarket_service_station' => 'boolean',
            'temporary_closure' => 'boolean',
            'permanent_closure' => 'boolean',
            'opening_times' => 'array',
            'amenities' => 'array',
            'data_corrections' => 'array',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(StationPrice::class, 'node_id', 'node_id');
    }
}
