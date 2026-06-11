<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\Tariff;
use App\Models\User;
use Carbon\Carbon;

class BillingService
{
    public function createSessionInvoice(ChargingSession $session): ?Invoice
    {
        $session->loadMissing(['user', 'station']);

        if (! $session->user?->usesCardPayment()) {
            return null;
        }

        $tariff = Tariff::query()->latest('id')->first();
        $pricePerKwh = $tariff?->price_per_kwh ?? (float) config('billing.price_per_kwh', 0.20);
        $totalKwh = (float) $session->kwh_consumed;
        $totalAmount = app(WalletService::class)->settleSession(
            $session,
            (float) ($tariff?->price_per_kwh ?? config('billing.price_per_kwh', 0.20))
        );

        if ($totalAmount <= 0 && $totalKwh > 0) {
            $totalAmount = round($totalKwh * (float) ($tariff?->price_per_kwh ?? config('billing.price_per_kwh', 0.20)), 2);
        }

        $paidFromWallet = app(WalletService::class)->enabled()
            && $session->user?->usesCardPayment()
            && (float) ($session->charge_budget ?? 0) > 0;
        $invoiceNumber = 'EVS-' . $session->id;
        $month = $session->end_time?->format('Y-m') ?? $session->start_time?->format('Y-m') ?? now()->format('Y-m');

        return Invoice::query()->updateOrCreate(
            ['source_session_id' => $session->id],
            [
                'user_id' => $session->user_id,
                'month' => $month,
                'currency' => $session->user?->currency ?? 'MDL',
                'invoice_type' => 'session',
                'invoice_number' => $invoiceNumber,
                'period_start' => $session->start_time?->toDateString(),
                'period_end' => $session->end_time?->toDateString() ?? $session->start_time?->toDateString(),
                'total_kwh' => round($totalKwh, 2),
                'total_amount' => $totalAmount,
                'sessions_count' => 1,
                'status' => $paidFromWallet ? 'paid' : 'unpaid',
                'paid_at' => $paidFromWallet ? now() : null,
                'payment_provider' => $paidFromWallet ? 'wallet' : null,
            ]
        );
    }

    public function finalizeBillingForSession(ChargingSession $session): ?Invoice
    {
        $session->loadMissing('user');

        if ($session->user?->usesMonthlyBilling()) {
            return $this->syncPersonalMonthlyInvoice($session);
        }

        return $this->createSessionInvoice($session);
    }

    public function syncPersonalMonthlyInvoice(ChargingSession $session): ?Invoice
    {
        $session->loadMissing('user');
        $user = $session->user;

        if (! $user?->usesMonthlyBilling() || ! $session->end_time) {
            return null;
        }

        return $this->upsertPersonalMonthlyInvoice($user, $session->end_time->copy()->startOfMonth());
    }

    public function generateMonthlyInvoices(?Carbon $targetMonth = null): int
    {
        $month = ($targetMonth ?? now()->subMonth())->startOfMonth();

        $users = User::query()
            ->where('is_admin', false)
            ->where('account_type', User::ACCOUNT_TYPE_PERSONAL)
            ->get();
        $createdCount = 0;

        foreach ($users as $user) {
            $invoice = $this->upsertPersonalMonthlyInvoice($user, $month);

            if ($invoice) {
                $createdCount++;
            }
        }

        return $createdCount;
    }

    private function upsertPersonalMonthlyInvoice(User $user, Carbon $month): ?Invoice
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $tariff = Tariff::query()->latest('id')->first();
        $pricePerKwh = $tariff?->price_per_kwh ?? (float) config('billing.price_per_kwh', 0.20);

        $sessionsQuery = ChargingSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('end_time')
            ->whereBetween('end_time', [$from, $to]);

        $sessionsCount = (clone $sessionsQuery)->count();

        if ($sessionsCount <= 0) {
            return null;
        }

        $totalKwh = (float) (clone $sessionsQuery)->sum('kwh_consumed');

        if ($totalKwh <= 0) {
            return null;
        }

        $existing = Invoice::query()
            ->where('user_id', $user->id)
            ->where('month', $month->format('Y-m'))
            ->where('invoice_type', 'monthly')
            ->first();

        $payload = [
            'invoice_number' => 'EVM-' . $month->format('Ym') . '-' . $user->id,
            'currency' => $user->currency ?? 'MDL',
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'total_kwh' => round($totalKwh, 2),
            'total_amount' => round($totalKwh * $pricePerKwh, 2),
            'sessions_count' => $sessionsCount,
        ];

        if (! $existing || $existing->status !== 'paid') {
            $payload['status'] = 'unpaid';
        }

        return Invoice::query()->updateOrCreate(
            ['user_id' => $user->id, 'month' => $month->format('Y-m'), 'invoice_type' => 'monthly'],
            $payload
        );
    }
}
