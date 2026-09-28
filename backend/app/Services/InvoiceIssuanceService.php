<?php

namespace App\Services;

use App\Jobs\SendInvoiceEmailJob;
use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\WalletTopup;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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

        // Do not invent zero-amount fiscal docs for unpaid/unsettled energy.
        if ($amount <= 0) {
            return null;
        }

        $end = Carbon::parse($session->end_time);
        $start = $session->start_time ? Carbon::parse($session->start_time) : $end;
        $stationName = $session->station?->name ?: 'statie EV';
        $quantity = $kwh > 0 ? $kwh : 1.0;
        $unit = $kwh > 0 ? 'kWh' : 'buc';
        $fiscal = $this->fiscalCalculator->breakdown($amount, $quantity);
        $seller = $this->fiscalCalculator->sellerSnapshot();

        $attributes = array_merge([
            'user_id' => $session->user_id,
            'source_session_id' => $session->id,
            'invoice_type' => 'session',
            'series' => null,
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
        ], $seller);

        $invoice = $this->createWithUniqueInvoiceNumber($attributes);
        $this->queueInvoiceEmail($invoice);

        return $invoice;
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
        if ($amount <= 0) {
            return null;
        }

        $fiscal = $this->fiscalCalculator->breakdown($amount, 1.0);
        $seller = $this->fiscalCalculator->sellerSnapshot();

        $attributes = array_merge([
            'user_id' => $topup->user_id,
            'wallet_topup_id' => $topup->id,
            'invoice_type' => 'wallet_topup',
            'series' => null,
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
        ], $seller);

        $invoice = $this->createWithUniqueInvoiceNumber($attributes);
        $this->queueInvoiceEmail($invoice);

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createWithUniqueInvoiceNumber(array $attributes): Invoice
    {
        $attempts = 0;

        while ($attempts < 8) {
            $attempts++;

            try {
                return DB::transaction(function () use ($attributes) {
                    // Re-check uniqueness guards inside the transaction.
                    if (! empty($attributes['source_session_id'])) {
                        $existing = Invoice::query()
                            ->where('source_session_id', $attributes['source_session_id'])
                            ->lockForUpdate()
                            ->first();
                        if ($existing) {
                            return $existing;
                        }
                    }

                    if (! empty($attributes['wallet_topup_id'])) {
                        $existing = Invoice::query()
                            ->where('wallet_topup_id', $attributes['wallet_topup_id'])
                            ->lockForUpdate()
                            ->first();
                        if ($existing) {
                            return $existing;
                        }
                    }

                    $attributes['invoice_number'] = $this->nextInvoiceNumber();

                    return Invoice::query()->create($attributes);
                });
            } catch (QueryException $exception) {
                if (! $this->isUniqueConstraintViolation($exception) || $attempts >= 8) {
                    throw $exception;
                }

                usleep(10_000 * $attempts);
            }
        }

        throw new \RuntimeException('Nu s-a putut aloca un numar de factura unic.');
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return $sqlState === '23000'
            || $driverCode === 1062
            || $driverCode === 19
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }

    private function queueInvoiceEmail(Invoice $invoice): void
    {
        $recipient = $invoice->buyer_email;
        if (! filled($recipient)) {
            $invoice->loadMissing('user:id,email');
            $recipient = $invoice->user?->email;
        }

        if (! filled($recipient)) {
            return;
        }

        SendInvoiceEmailJob::dispatch($invoice->id);
    }

    private function nextInvoiceNumber(): string
    {
        $max = 0;

        foreach (Invoice::query()->whereNotNull('invoice_number')->pluck('invoice_number') as $number) {
            if (preg_match('/^\d{1,7}$/', (string) $number)) {
                $max = max($max, (int) $number);
            }
        }

        return str_pad((string) ($max + 1), 7, '0', STR_PAD_LEFT);
    }
}
