<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\WalletTopup;
use Carbon\Carbon;

class InvoiceIssuanceService
{
    public function __construct(
        private readonly InvoiceFiscalCalculator $fiscalCalculator,
    ) {
    }

    public function createSessionInvoice(ChargingSession $session, float $chargedAmount): ?Invoice
    {
        $session->loadMissing(['user', 'station:id,name']);

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
        $kwh = round((float) $session->kwh_consumed, 3);

        if ($amount <= 0 && $kwh <= 0) {
            return null;
        }

        $end = Carbon::parse($session->end_time);
        $start = $session->start_time ? Carbon::parse($session->start_time) : $end;
        $stationName = $session->station?->name ?: 'statie EV';
        $quantity = $kwh > 0 ? $kwh : 1.0;
        $unit = $kwh > 0 ? 'kWh' : 'buc';
        $fiscal = $this->fiscalCalculator->breakdown($amount, $quantity);
        $seller = $this->fiscalCalculator->sellerSnapshot();

        return Invoice::query()->create(array_merge([
            'user_id' => $session->user_id,
            'source_session_id' => $session->id,
            'invoice_type' => 'session',
            'series' => (string) config('invoice.series', 'VE'),
            'invoice_number' => $this->nextInvoiceNumber((string) config('invoice.series', 'VE')),
            'month' => $end->format('Y-m'),
            'currency' => $session->user->currency ?? 'MDL',
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'total_kwh' => $kwh,
            'total_amount' => $fiscal['amount_gross'],
            'sessions_count' => 1,
            'line_description' => sprintf(
                'Servicii de incarcare vehicul electric · %s · %.3f kWh',
                $stationName,
                $kwh
            ),
            'unit' => $unit,
            'quantity' => $quantity,
            'unit_price' => $fiscal['unit_price'],
            'vat_rate' => $fiscal['vat_rate'],
            'amount_net' => $fiscal['amount_net'],
            'amount_vat' => $fiscal['amount_vat'],
            'buyer_name' => $session->user->name,
            'buyer_email' => $session->user->email,
            'status' => 'paid',
            'paid_at' => $end,
            'issued_at' => $end,
            'payment_provider' => 'wallet',
        ], $seller));
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
        $amount = round((float) $topup->amount, 2);
        $fiscal = $this->fiscalCalculator->breakdown($amount, 1.0);
        $seller = $this->fiscalCalculator->sellerSnapshot();

        return Invoice::query()->create(array_merge([
            'user_id' => $topup->user_id,
            'wallet_topup_id' => $topup->id,
            'invoice_type' => 'wallet_topup',
            'series' => (string) config('invoice.series', 'VE'),
            'invoice_number' => $this->nextInvoiceNumber((string) config('invoice.series', 'VE')),
            'month' => $paidAt->format('Y-m'),
            'currency' => $topup->currency ?: ($topup->user->currency ?? 'MDL'),
            'period_start' => $paidAt->toDateString(),
            'period_end' => $paidAt->toDateString(),
            'total_kwh' => 0,
            'total_amount' => $fiscal['amount_gross'],
            'sessions_count' => 0,
            'line_description' => 'Alimentare sold preplatit pentru servicii de incarcare EV',
            'unit' => 'buc',
            'quantity' => 1,
            'unit_price' => $fiscal['unit_price'],
            'vat_rate' => $fiscal['vat_rate'],
            'amount_net' => $fiscal['amount_net'],
            'amount_vat' => $fiscal['amount_vat'],
            'buyer_name' => $topup->user->name,
            'buyer_email' => $topup->user->email,
            'status' => 'paid',
            'paid_at' => $paidAt,
            'issued_at' => $paidAt,
            'payment_provider' => $topup->payment_provider ?: 'stripe',
            'payment_session_id' => $topup->payment_session_id,
        ], $seller));
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
