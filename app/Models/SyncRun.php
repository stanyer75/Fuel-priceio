<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    public const STATIONS = 'stations';

    public const PRICES_FULL = 'prices_full';

    public const PRICES_INCREMENTAL = 'prices_incremental';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rejections' => 'array',
            'corrections' => 'array',
            'changed_since' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function scopeSucceeded(Builder $query): void
    {
        $query->where('status', 'succeeded');
    }

    public static function lastSuccessfulPrices(): ?self
    {
        return self::succeeded()
            ->whereIn('type', [self::PRICES_FULL, self::PRICES_INCREMENTAL])
            ->latest('started_at')->latest('id')
            ->first();
    }

    public static function lastSuccessfulStations(): ?self
    {
        return self::succeeded()->where('type', self::STATIONS)->latest('started_at')->latest('id')->first();
    }
}
