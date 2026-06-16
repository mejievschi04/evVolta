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

        $topup->loadMissing('user');
        if ($topup->user) {
            app(PushNotificationService::class)->notifyWalletTopupPaid(
                $topup->user,
                (float) $topup->amount,
                $topup->currency ?: ($topup->user->currency ?? 'MDL')
            );
        }
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

        $refunded = $amount;

        DB::transaction(function () use ($topup, $user, $amount, $stripePaymentService, &$refunded) {
            $topup = WalletTopup::query()->lockForUpdate()->findOrFail($topup->id);
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            $slice = round(min($amount, $topup->refundableAmount(), $this->balance($user)), 2);
            $refunded = $slice;

            if ($slice <= 0) {
                throw new RuntimeException('Nu mai exista suma disponibila pentru retur.', 422);
            }

            $stripeRefundId = null;

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
                $stripeRefundId = $stripeRefund['id'] ?? null;
            }

            WalletRefund::query()->create([
                'user_id' => $user->id,
                'wallet_topup_id' => $topup->id,
                'amount' => $slice,
                'currency' => $topup->currency ?: ($user->currency ?: 'MDL'),
                'status' => 'completed',
                'payment_provider' => $topup->payment_provider ?: 'local',
                'stripe_refund_id' => $stripeRefundId,
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
