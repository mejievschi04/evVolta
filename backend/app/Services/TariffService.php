<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Tariff;
use App\Models\User;

class TariffService
{
    public function globalPricePerKwh(): float
    {
        return Tariff::normalizePrice(
            Tariff::query()->latest('id')->value('price_per_kwh')
                ?? config('billing.price_per_kwh', 0.20)
        );
    }

    public function personalPricePerKwh(): float
    {
        $latest = Tariff::query()->latest('id')->first(['personal_price_per_kwh', 'price_per_kwh']);

        if ($latest?->personal_price_per_kwh !== null) {
            return Tariff::normalizePrice($latest->personal_price_per_kwh);
        }

        return $this->globalPricePerKwh();
    }

    public function servicePricePerKwh(): float
    {
        return 0.0;
    }

    /**
     * Price to start a session at — stored on the session so a tariff change mid-session
     * is not applied retroactively. Free-charging accounts keep no snapshot.
     */
    public function snapshotPriceForNewSession(User $user): ?float
    {
        return $user->isFreeCharging() ? null : $this->pricePerKwhForUser($user);
    }

    /**
     * Price a session is billed at: the tariff captured at its start, or the current one for
     * sessions started before snapshots existed.
     */
    public function pricePerKwhForSession(ChargingSession $session): float
    {
        $session->loadMissing('user');

        if ($session->price_per_kwh !== null && ! $session->user?->isFreeCharging()) {
            return Tariff::normalizePrice($session->price_per_kwh);
        }

        return $this->pricePerKwhForUser($session->user);
    }

    public function pricePerKwhForUser(?User $user): float
    {
        if ($user?->isFreeCharging()) {
            return $this->servicePricePerKwh();
        }

        if ($user?->isPersonalAccount()) {
            return $this->personalPricePerKwh();
        }

        return $this->globalPricePerKwh();
    }
}
