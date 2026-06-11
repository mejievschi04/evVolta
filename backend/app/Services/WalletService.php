<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\User;
use App\Models\WalletTopup;
use RuntimeException;

class WalletService
{
    public function enabled(): bool
    {
        return (bool) config('billing.prepaid_wallet_enabled', false);
    }

    public function balance(User $user): float
    {
        return round((float) $user->wallet_balance, 2);
    }

    public function assertCanHoldBudget(User $user, float $budgetAmount): void
    {
        if (! $this->enabled() || ! $user->usesCardPayment()) {
            return;
        }

        if ($budgetAmount <= 0) {
            throw new RuntimeException('Selecteaza suma pentru incarcare.', 422);
        }

        if ($this->balance($user) < $budgetAmount) {
            throw new RuntimeException('Sold insuficient. Alimenteaza contul inainte de pornire.', 422);
        }
    }

    public function holdBudgetForSession(User $user, ChargingSession $session, float $budgetAmount): void
    {
        if (! $this->enabled() || ! $user->usesCardPayment()) {
            return;
        }

        $this->assertCanHoldBudget($user, $budgetAmount);

        $user->decrement('wallet_balance', $budgetAmount);
        $session->update(['charge_budget' => round($budgetAmount, 2)]);
    }

    public function settleSession(ChargingSession $session, float $pricePerKwh): float
    {
        $session->loadMissing('user');
        $user = $session->user;

        if (! $this->enabled() || ! $user?->usesCardPayment()) {
            return round((float) $session->kwh_consumed * $pricePerKwh, 2);
        }

        $budget = (float) ($session->charge_budget ?? 0);
        $actualCost = round((float) $session->kwh_consumed * $pricePerKwh, 2);

        if ($budget <= 0) {
            return $actualCost;
        }

        $charged = round(min($actualCost, $budget), 2);
        $refund = round(max(0, $budget - $charged), 2);

        if ($refund > 0) {
            $user->increment('wallet_balance', $refund);
        }

        return $charged;
    }

    public function creditTopup(WalletTopup $topup): void
    {
        if ($topup->status === 'paid') {
            return;
        }

        $topup->update([
            'status' => 'paid',
            'paid_at' => now(),
            'payment_provider' => 'stripe',
        ]);

        $topup->user()->increment('wallet_balance', (float) $topup->amount);
    }

    public function estimatedCostForSession(ChargingSession $session, ?float $pricePerKwh = null): float
    {
        $pricePerKwh ??= (float) (Tariff::query()->latest('id')->value('price_per_kwh')
            ?? config('billing.price_per_kwh', 0.20));

        return round(app(SessionEnergyService::class)->telemetryKwhDelivered($session) * $pricePerKwh, 2);
    }

    public function shouldStopForBudget(ChargingSession $session, ?float $pricePerKwh = null): bool
    {
        $budget = (float) ($session->charge_budget ?? 0);

        if (! $this->enabled() || $budget <= 0 || $session->end_time) {
            return false;
        }

        $estimated = $this->estimatedCostForSession($session, $pricePerKwh);

        return $estimated >= $budget;
    }

    public function maybeAutoStopForBudget(ChargingSession $session, Station $station): void
    {
        if (! $this->enabled()) {
            return;
        }

        $session = $session->fresh();

        if (! $this->shouldStopForBudget($session)) {
            return;
        }

        $session = $session->fresh(['user', 'station']);
        if ($session->user && $session->station) {
            app(\App\Services\PushNotificationService::class)->notifyBudgetAutoStop(
                $session->user,
                $session->station->name
            );
        }

        app(ChargingStopService::class)->requestStop($session, $station->fresh(), 'budget');
    }
}
