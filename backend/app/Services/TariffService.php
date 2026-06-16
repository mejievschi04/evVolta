<?php

namespace App\Services;

use App\Models\Tariff;
use App\Models\User;

class TariffService
{
    public function globalPricePerKwh(): float
    {
        return (float) (Tariff::query()->latest('id')->value('price_per_kwh')
            ?? config('billing.price_per_kwh', 0.20));
    }

    public function personalPricePerKwh(): float
    {
        $latest = Tariff::query()->latest('id')->first(['personal_price_per_kwh', 'price_per_kwh']);

        if ($latest?->personal_price_per_kwh !== null) {
            return (float) $latest->personal_price_per_kwh;
        }

        return $this->globalPricePerKwh();
    }

    public function pricePerKwhForUser(?User $user): float
    {
        if ($user?->isPersonalAccount()) {
            return $this->personalPricePerKwh();
        }

        return $this->globalPricePerKwh();
    }
}
