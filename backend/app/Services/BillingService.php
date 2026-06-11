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
        return $this->finalizeBillingForSession($session);
    }

    public function finalizeBillingForSession(ChargingSession $session): ?Invoice
    {
        $session->loadMissing('user');

        if ($session->user?->usesCardPayment()) {
            $tariff = Tariff::query()->latest('id')->first();
            app(WalletService::class)->settleSession(
                $session,
                (float) ($tariff?->price_per_kwh ?? config('billing.price_per_kwh', 0.20))
            );
        }

        // Monthly invoices are issued on the 1st for the previous month.
        return null;
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
