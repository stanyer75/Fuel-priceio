<?php

namespace App\Models;

use App\Enums\FuelType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationPrice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fuel_type' => FuelType::class,
            'price_ppl' => 'float',
            'price_updated_at' => 'immutable_datetime',
            'data_corrections' => 'array',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'node_id', 'node_id');
    }
}
