<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tariff extends Model
{
    protected $fillable = [
        'price_per_kwh',
        'personal_price_per_kwh',
    ];

    protected function casts(): array
    {
        return [
            'price_per_kwh' => 'float',
            'personal_price_per_kwh' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Tariff $tariff): void {
            if ($tariff->price_per_kwh !== null) {
                $tariff->price_per_kwh = self::normalizePrice($tariff->price_per_kwh);
            }

            if ($tariff->personal_price_per_kwh !== null) {
                $tariff->personal_price_per_kwh = self::normalizePrice($tariff->personal_price_per_kwh);
            }
        });
    }

    public static function normalizePrice(float|int|string|null $value): float
    {
        return round((float) $value, 2);
    }
}
