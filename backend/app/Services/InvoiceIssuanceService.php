<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\WalletTopup;
use Carbon\Carbon;

class InvoiceIssuanceService
{
    public function createSessionInvoice(ChargingSession $session, float $chargedAmount): ?Invoice
    {
        $session->loadMissing('user');

        if (! $session->user?->usesCardPayment() || ! $session->end_time) {
            return null;
        }

        $existing = Invoice::query()
            ->where('source_session_id', $session->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $amount = round(max(0, $chargedAmount), 2);
        $kwh = round((float) $session->kwh_consumed, 2);

        if ($amount <= 0 && $kwh <= 0) {
            return null;
        }

        $end = Carbon::parse($session->end_time);
        $start = $session->start_time ? Carbon::parse($session->start_time) : $end;

        return Invoice::query()->create([
            'user_id' => $session->user_id,
            'source_session_id' => $session->id,
            'invoice_type' => 'session',
            'invoice_number' => $this->nextInvoiceNumber('EVS'),
            'month' => $end->format('Y-m'),
            'currency' => $session->user->currency ?? 'MDL',
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'total_kwh' => $kwh,
            'total_amount' => $amount,
            'sessions_count' => 1,
            'status' => 'paid',
            'paid_at' => $end,
            'payment_provider' => 'wallet',
        ]);
    }

    public function createWalletTopupInvoice(WalletTopup $topup): ?Invoice
    {
        $topup->loadMissing('user');

        if ($topup->status !== 'paid' || ! $topup->user?->usesCardPayment()) {
            return null;
        }

        $existing = Invoice::query()
            ->where('wallet_topup_id', $topup->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $paidAt = $topup->paid_at ? Carbon::parse($topup->paid_at) : now();

        return Invoice::query()->create([
            'user_id' => $topup->user_id,
            'wallet_topup_id' => $topup->id,
            'invoice_type' => 'wallet_topup',
            'invoice_number' => $this->nextInvoiceNumber('EVP'),
            'month' => $paidAt->format('Y-m'),
            'currency' => $topup->currency ?: ($topup->user->currency ?? 'MDL'),
            'period_start' => $paidAt->toDateString(),
            'period_end' => $paidAt->toDateString(),
            'total_kwh' => 0,
            'total_amount' => round((float) $topup->amount, 2),
            'sessions_count' => 0,
            'status' => 'paid',
            'paid_at' => $paidAt,
            'payment_provider' => $topup->payment_provider ?: 'stripe',
            'payment_session_id' => $topup->payment_session_id,
        ]);
    }

    private function nextInvoiceNumber(string $prefix): string
    {
        $date = now()->format('Ymd');
        $sequence = Invoice::query()
            ->whereDate('created_at', today())
            ->count() + 1;

        return sprintf('%s-%s-%04d', $prefix, $date, $sequence);
    }
}
