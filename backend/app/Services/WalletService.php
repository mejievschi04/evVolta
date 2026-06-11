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
    public const MIN_BUDGET_AMOUNT = 10;

    public const MAX_BUDGET_AMOUNT = 50000;

    public const MAX_TARGET_KWH = 500;
    public function enabled(): bool
    {
        return (bool) config('billing.prepaid_wallet_enabled', false);
    }

    public function balance(User $user): float
    {
        return round((float) $user->wallet_balance, 2);
    }

    public function currentPricePerKwh(): float
    {
        return (float) (Tariff::query()->latest('id')->value('price_per_kwh')
            ?? config('billing.price_per_kwh', 0.20));
    }

    public function minTargetKwh(): float
    {
        $price = $this->currentPricePerKwh();

        if ($price <= 0) {
            return 1;
        }

        return round(self::MIN_BUDGET_AMOUNT / $price, 2);
    }

    /**
     * @return array{budget_amount: float, target_kwh: ?float}
     */
    public function resolvePrepaidStart(?float $budgetAmount, ?float $targetKwh): array
    {
        $hasBudget = $budgetAmount !== null && $budgetAmount > 0;
        $hasTargetKwh = $targetKwh !== null && $targetKwh > 0;

        if ($hasBudget && $hasTargetKwh) {
            throw new RuntimeException('Alege fie suma, fie cantitatea de kWh, nu ambele.', 422);
        }

        if ($hasBudget) {
            return [
                'budget_amount' => round($budgetAmount, 2),
                'target_kwh' => null,
            ];
        }

        if ($hasTargetKwh) {
            $targetKwh = round($targetKwh, 3);
            $minTargetKwh = $this->minTargetKwh();

            if ($targetKwh < $minTargetKwh) {
                throw new RuntimeException(
                    sprintf('Minim %.2f kWh la tariful curent.', $minTargetKwh),
                    422
                );
            }

            if ($targetKwh > self::MAX_TARGET_KWH) {
                throw new RuntimeException(
                    sprintf('Maxim %.0f kWh per sesiune.', self::MAX_TARGET_KWH),
                    422
                );
            }

            $budgetAmount = round($targetKwh * $this->currentPricePerKwh(), 2);

            if ($budgetAmount < self::MIN_BUDGET_AMOUNT) {
                throw new RuntimeException('Bugetul calculat este prea mic. Alege mai multi kWh.', 422);
            }

            return [
                'budget_amount' => $budgetAmount,
                'target_kwh' => $targetKwh,
            ];
        }

        throw new RuntimeException('Selecteaza suma sau cantitatea de kWh pentru incarcare.', 422);
    }

    /**
     * @return array<string, mixed>
     */
    public function chargeOptions(): array
    {
        $price = $this->currentPricePerKwh();

        return [
            'price_per_kwh' => $price,
            'currency' => 'MDL',
            'min_budget' => self::MIN_BUDGET_AMOUNT,
            'max_budget' => self::MAX_BUDGET_AMOUNT,
            'min_target_kwh' => $this->minTargetKwh(),
            'max_target_kwh' => self::MAX_TARGET_KWH,
            'suggested_budgets' => [50, 100, 200, 500],
            'suggested_kwh' => [10, 20, 30, 50],
        ];
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

    public function holdBudgetForSession(User $user, ChargingSession $session, float $budgetAmount, ?float $targetKwh = null): void
    {
        if (! $this->enabled() || ! $user->usesCardPayment()) {
            return;
        }

        $this->assertCanHoldBudget($user, $budgetAmount);

        $user->decrement('wallet_balance', $budgetAmount);
        $session->update([
            'charge_budget' => round($budgetAmount, 2),
            'target_kwh' => $targetKwh !== null ? round($targetKwh, 3) : null,
        ]);
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
        $targetKwh = (float) ($session->target_kwh ?? 0);

        if (! $this->enabled() || $session->end_time) {
            return false;
        }

        if ($budget <= 0 && $targetKwh <= 0) {
            return false;
        }

        $kwhDelivered = app(SessionEnergyService::class)->telemetryKwhDelivered($session);

        if ($targetKwh > 0 && $kwhDelivered >= $targetKwh) {
            return true;
        }

        if ($budget <= 0) {
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
