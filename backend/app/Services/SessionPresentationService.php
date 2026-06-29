<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Invoice;

class SessionPresentationService
{
    public function __construct(
        private readonly BillingService $billingService,
        private readonly TariffService $tariffService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function presentForUser(ChargingSession $session, bool $ensureInvoice = false): array
    {
        $session->loadMissing(['station', 'user', 'invoice']);

        if ($ensureInvoice && $session->end_time) {
            $this->billingService->ensureSessionInvoice($session);
            $session->load('invoice');
        }

        $invoice = $session->invoice;
        $currency = $invoice?->currency ?: $session->user?->currency ?: 'MDL';
        $amountSpent = $session->end_time
            ? ($invoice?->total_amount ?? $this->billingService->estimatedSessionCharge($session))
            : null;

        if ($session->station) {
            $session->station->setAttribute(
                'live_status',
                $session->station->liveStatus((int) ($session->ocpp_connector_id ?: 1))
            );
        }

        $payload = $session->toArray();
        $payload['billing'] = [
            'amount_spent' => $amountSpent !== null ? round((float) $amountSpent, 2) : null,
            'currency' => $currency,
            'price_per_kwh' => $this->tariffService->pricePerKwhForUser($session->user),
        ];
        $payload['invoice'] = $this->invoiceSummary($invoice);

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function invoiceSummary(?Invoice $invoice): ?array
    {
        if (! $invoice) {
            return null;
        }

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_type' => $invoice->invoice_type,
            'status' => $invoice->status,
            'total_amount' => round((float) $invoice->total_amount, 2),
            'total_kwh' => round((float) $invoice->total_kwh, 2),
            'currency' => $invoice->currency,
            'paid_at' => $invoice->paid_at,
        ];
    }
}
