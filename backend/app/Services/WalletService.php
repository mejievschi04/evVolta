<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use App\Models\WalletRefund;
use App\Models\WalletTopup;
use Illuminate\Support\Facades\DB;
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

    public function devTopupEnabled(): bool
    {
        return app()->environment('local') || (bool) config('billing.wallet_dev_topup_enabled', false);
    }

    public function devTopupMaxAmount(): float
    {
        return max(self::MIN_BUDGET_AMOUNT, (float) config('billing.wallet_dev_topup_max_amount', 1000));
    }

    public function devTopupDailyLimit(): float
    {
        return max(self::MIN_BUDGET_AMOUNT, (float) config('billing.wallet_dev_topup_daily_limit', 5000));
    }

    public function devTopupDailyCredited(User $user): float
    {
        return round((float) WalletTopup::query()
            ->where('user_id', $user->id)
            ->where('payment_provider', 'local')
            ->where('status', 'paid')
            ->where('paid_at', '>=', now()->startOfDay())
            ->sum('amount'), 2);
    }

    /**
     * @throws RuntimeException
     */
    public function assertDevTopupAllowed(User $user, float $amount): void
    {
        if (! $this->devTopupEnabled() || ! $this->enabled()) {
            throw new RuntimeException('Not found.', 404);
        }

        if (! $user->usesCardPayment()) {
            throw new RuntimeException('Alimentarea wallet nu este disponibila pentru acest cont.', 422);
        }

        $amount = round($amount, 2);
        $maxAmount = $this->devTopupMaxAmount();

        if ($amount < self::MIN_BUDGET_AMOUNT) {
            throw new RuntimeException(
                sprintf('Minim %.2f MDL.', self::MIN_BUDGET_AMOUNT),
                422
            );
        }

        if ($amount > $maxAmount) {
            throw new RuntimeException(
                sprintf('Maxim %.2f MDL per alimentare test.', $maxAmount),
                422
            );
        }

        $dailyLimit = $this->devTopupDailyLimit();
        $dailyTotal = $this->devTopupDailyCredited($user);

        if ($dailyTotal + $amount > $dailyLimit) {
            throw new RuntimeException(
                sprintf(
                    'Limita zilnica de alimentare test este %.2f MDL (ramas %.2f MDL).',
                    $dailyLimit,
                    max(0, $dailyLimit - $dailyTotal)
                ),
                422
            );
        }
    }

    public function balance(User $user): float
    {
        return round((float) $user->wallet_balance, 2);
    }

    public function currentPricePerKwh(?User $user = null): float
    {
        return app(TariffService::class)->pricePerKwhForUser($user);
    }

    public function minTargetKwh(?User $user = null): float
    {
        $price = $this->currentPricePerKwh($user);

        if ($price <= 0) {
            return 1;
        }

        return round(self::MIN_BUDGET_AMOUNT / $price, 2);
    }

    /**
     * @return array{budget_amount: float, target_kwh: ?float}
     */
    public function resolvePrepaidStart(?float $budgetAmount, ?float $targetKwh, ?User $user = null): array
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
            $minTargetKwh = $this->minTargetKwh($user);

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

            $budgetAmount = round($targetKwh * $this->currentPricePerKwh($user), 2);

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
    public function chargeOptions(?User $user = null): array
    {
        $price = $this->currentPricePerKwh($user);

        return [
            'price_per_kwh' => $price,
            'currency' => 'MDL',
            'min_budget' => self::MIN_BUDGET_AMOUNT,
            'max_budget' => self::MAX_BUDGET_AMOUNT,
            'min_target_kwh' => $this->minTargetKwh($user),
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
        return DB::transaction(function () use ($session, $pricePerKwh): float {
            $session = ChargingSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();
            $session->loadMissing('user');

            $user = $session->user;
            $actualCost = round((float) $session->kwh_consumed * $pricePerKwh, 2);
            $budget = (float) ($session->charge_budget ?? 0);

            if (! $user?->usesCardPayment() || $budget <= 0) {
                return $actualCost;
            }

            $user = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $charged = round(min($actualCost, $budget), 2);
            $refund = round(max(0, $budget - $charged), 2);

            if ($refund > 0) {
                $user->increment('wallet_balance', $refund);
            }

            // Mark the hold as settled by shrinking the remaining budget to the
            // charged amount. Re-running settlement then computes a zero refund.
            $session->update(['charge_budget' => $charged]);

            return $charged;
        });
    }

    public function creditTopup(
        WalletTopup $topup,
        ?string $paymentSessionId = null,
        ?string $paymentIntentId = null,
    ): void {
        if ($topup->status === 'paid') {
            return;
        }

        $topup->update([
            'status' => 'paid',
            'paid_at' => now(),
            'payment_provider' => $topup->payment_provider ?: 'stripe',
            'payment_session_id' => $paymentSessionId ?: $topup->payment_session_id,
            'payment_intent_id' => $paymentIntentId ?: $topup->payment_intent_id,
        ]);

        $topup->user()->increment('wallet_balance', (float) $topup->amount);

        app(InvoiceIssuanceService::class)->createWalletTopupInvoice($topup->fresh());
    }

    /**
     * @return array{topup_id: int, credited: float, wallet_balance: float, currency: string}
     */
    public function creditManualTopup(User $user, float $amount): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Wallet prepay nu este activ.', 422);
        }

        if (! $user->usesCardPayment()) {
            throw new RuntimeException('Contul nu foloseste wallet prepay.', 422);
        }

        $amount = round($amount, 2);

        if ($amount < self::MIN_BUDGET_AMOUNT) {
            throw new RuntimeException(
                sprintf('Minim %.2f MDL.', self::MIN_BUDGET_AMOUNT),
                422
            );
        }

        if ($amount > self::MAX_BUDGET_AMOUNT) {
            throw new RuntimeException(
                sprintf('Maxim %.2f MDL.', self::MAX_BUDGET_AMOUNT),
                422
            );
        }

        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => $amount,
            'currency' => $user->currency ?? 'MDL',
            'status' => 'pending',
            'payment_provider' => 'manual',
        ]);

        $this->creditTopup($topup);

        return [
            'topup_id' => $topup->id,
            'credited' => $amount,
            'wallet_balance' => $this->balance($user->fresh()),
            'currency' => $user->currency ?? 'MDL',
        ];
    }

    public function refundableBalance(User $user): float
    {
        if (! $this->enabled() || ! $user->usesCardPayment()) {
            return 0.0;
        }

        $fromTopups = (float) WalletTopup::query()
            ->where('user_id', $user->id)
            ->where('status', 'paid')
            ->selectRaw('COALESCE(SUM(amount - amount_refunded), 0) as total')
            ->value('total');

        return round(min($this->balance($user), $fromTopups), 2);
    }

    public function hasOpenChargingSession(User $user): bool
    {
        return ChargingSession::query()
            ->where('user_id', $user->id)
            ->whereNull('end_time')
            ->exists();
    }

    /**
     * @return array{
     *     refunded: float,
     *     wallet_balance: float,
     *     currency: string,
     *     topup_amount_refunded: float,
     *     topup_refundable_amount: float
     * }
     */
    public function refundTopup(
        WalletTopup $topup,
        StripePaymentService $stripePaymentService,
        ?float $amount = null,
        ?MaibPaymentService $maibPaymentService = null,
    ): array {
        $topup->loadMissing('user');
        $user = $topup->user;

        if (! $user) {
            throw new RuntimeException('Utilizatorul alimentarii nu a fost gasit.', 422);
        }

        if (! $this->enabled() || ! $user->usesCardPayment()) {
            throw new RuntimeException('Returul nu este disponibil pentru acest cont.', 422);
        }

        if ($topup->status !== 'paid') {
            throw new RuntimeException('Doar alimentarile platite pot fi returnate.', 422);
        }

        $availableOnTopup = $topup->refundableAmount();
        if ($availableOnTopup <= 0) {
            throw new RuntimeException('Aceasta alimentare a fost deja returnata integral.', 422);
        }

        $amount = $amount !== null ? round($amount, 2) : $availableOnTopup;

        if ($amount <= 0) {
            throw new RuntimeException('Introdu o suma valida pentru retur.', 422);
        }

        if ($amount > $availableOnTopup) {
            throw new RuntimeException('Suma depaseste restul nereturnat din aceasta alimentare.', 422);
        }

        if ($amount > $this->balance($user)) {
            throw new RuntimeException('Soldul clientului nu acopera suma de returnat.', 422);
        }

        if ($this->hasOpenChargingSession($user)) {
            throw new RuntimeException('Clientul are o incarcare activa. Opreste sesiunea inainte de retur.', 422);
        }

        $maibPaymentService ??= app(MaibPaymentService::class);
        $refunded = $amount;

        DB::transaction(function () use ($topup, $user, $amount, $stripePaymentService, $maibPaymentService, &$refunded) {
            $topup = WalletTopup::query()->lockForUpdate()->findOrFail($topup->id);
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            $slice = round(min($amount, $topup->refundableAmount(), $this->balance($user)), 2);
            $refunded = $slice;

            if ($slice <= 0) {
                throw new RuntimeException('Nu mai exista suma disponibila pentru retur.', 422);
            }

            $providerRefundId = null;

            if ($topup->payment_provider === 'stripe') {
                if (! $topup->payment_intent_id) {
                    throw new RuntimeException(
                        'Plata originala nu poate fi returnata automat. Lipseste payment_intent.',
                        422
                    );
                }

                if (! $stripePaymentService->isConfigured()) {
                    throw new RuntimeException('Stripe nu este configurat.', 422);
                }

                $stripeRefund = $stripePaymentService->refundPaymentIntent(
                    $topup->payment_intent_id,
                    $slice,
                    $topup->currency ?: ($user->currency ?: 'MDL')
                );
                $providerRefundId = $stripeRefund['id'] ?? null;
            } elseif ($topup->payment_provider === 'maib') {
                if (! $topup->payment_session_id) {
                    throw new RuntimeException(
                        'Plata originala nu poate fi returnata automat. Lipseste checkoutId MAIB.',
                        422
                    );
                }

                if (! $maibPaymentService->isConfigured()) {
                    throw new RuntimeException('MAIB nu este configurat.', 422);
                }

                // MAIB allows only one refund per payment.
                if ((float) $topup->amount_refunded > 0) {
                    throw new RuntimeException(
                        'MAIB permite o singura returnare pe plata. Contacteaza suportul bancar pentru rest.',
                        422
                    );
                }

                $maibRefund = $maibPaymentService->refund((string) $topup->payment_session_id, $slice);
                $providerRefundId = $maibRefund['id'] ?? $topup->payment_session_id;
            }

            WalletRefund::query()->create([
                'user_id' => $user->id,
                'wallet_topup_id' => $topup->id,
                'amount' => $slice,
                'currency' => $topup->currency ?: ($user->currency ?: 'MDL'),
                'status' => 'completed',
                'payment_provider' => $topup->payment_provider ?: 'local',
                'stripe_refund_id' => $providerRefundId,
            ]);

            $topup->increment('amount_refunded', $slice);
            $user->decrement('wallet_balance', $slice);
        });

        $topup = $topup->fresh();
        $user = $user->fresh();

        return [
            'refunded' => $refunded,
            'wallet_balance' => $this->balance($user),
            'currency' => $user->currency ?? 'MDL',
            'topup_amount_refunded' => round((float) $topup->amount_refunded, 2),
            'topup_refundable_amount' => $topup->refundableAmount(),
        ];
    }

    public function estimatedCostForSession(ChargingSession $session, ?float $pricePerKwh = null): float
    {
        $session->loadMissing('user');
        $pricePerKwh ??= $this->currentPricePerKwh($session->user);

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

        app(ChargingStopService::class)->requestStop($session, $station->fresh(), 'budget');
    }

    public function assertCanChargeReservationFee(User $user, float $feeAmount): void
    {
        if ($feeAmount <= 0 || ! $this->enabled() || ! $user->usesCardPayment()) {
            return;
        }

        if ($this->balance($user) < $feeAmount) {
            throw new RuntimeException('Sold insuficient pentru taxa de rezervare.', 422);
        }
    }

    public function chargeReservationFee(User $user, \App\Models\Reservation $reservation, float $amount): void
    {
        if ($amount <= 0 || ! $this->enabled() || ! $user->usesCardPayment()) {
            return;
        }

        $this->assertCanChargeReservationFee($user, $amount);
        $user->decrement('wallet_balance', $amount);
        $reservation->update(['fee_charged' => true]);
    }

    public function refundReservationFee(User $user, \App\Models\Reservation $reservation): void
    {
        if (! $reservation->fee_charged || ! $this->enabled() || ! $user->usesCardPayment()) {
            return;
        }

        $amount = round((float) $reservation->fee_amount, 2);
        if ($amount <= 0) {
            return;
        }

        $user->increment('wallet_balance', $amount);
        $reservation->update(['fee_charged' => false]);
    }

    public function chargeNoShowFee(User $user, \App\Models\Reservation $reservation, float $amount): void
    {
        if ($amount <= 0 || ! $this->enabled() || ! $user->usesCardPayment()) {
            $reservation->update(['no_show_charged' => true]);

            return;
        }

        $charge = round(min($amount, $this->balance($user)), 2);
        if ($charge > 0) {
            $user->decrement('wallet_balance', $charge);
        }

        $reservation->update(['no_show_charged' => true]);
    }
}
