<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Invoice;
use Illuminate\Support\Collection;

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
    public function presentForUser(
        ChargingSession $session,
        bool $ensureInvoice = false,
        ?float $pricePerKwh = null,
        ?array $liveStatus = null,
    ): array {
        $session->loadMissing(['station', 'user', 'invoice']);

        if ($ensureInvoice && $session->end_time) {
            $this->billingService->ensureSessionInvoice($session);
            $session->load('invoice');
        }

        $pricePerKwh ??= $this->tariffService->pricePerKwhForUser($session->user);
        $invoice = $session->invoice;
        $currency = $invoice?->currency ?: $session->user?->currency ?: 'MDL';
        $amountSpent = $session->end_time
            ? ($invoice?->total_amount ?? $this->billingService->estimatedSessionCharge($session, $pricePerKwh))
            : null;

        if ($session->station) {
            $session->station->setAttribute(
                'live_status',
                $liveStatus ?? $session->station->liveStatus((int) ($session->ocpp_connector_id ?: 1))
            );
        }

        $session->presentationPricePerKwh = $pricePerKwh;
        $payload = $session->toArray();
        $payload['billing'] = [
            'amount_spent' => $amountSpent !== null ? round((float) $amountSpent, 2) : null,
            'currency' => $currency,
            'price_per_kwh' => $pricePerKwh,
        ];
        $payload['invoice'] = $this->invoiceSummary($invoice);

        return $payload;
    }

    /**
     * Prezinta mai multe sesiuni ale aceluiasi client intr-o cerere: tariful si starea
     * live a fiecarui port se calculeaza o singura data, nu pentru fiecare sesiune.
     *
     * @param  Collection<int, ChargingSession>  $sessions
     * @param  callable(ChargingSession): bool  $ensureInvoice
     * @return Collection<int, array<string, mixed>>
     */
    public function presentManyForUser(Collection $sessions, callable $ensureInvoice): Collection
    {
        $prices = [];
        $liveStatuses = [];

        return $sessions->map(function (ChargingSession $session) use ($ensureInvoice, &$prices, &$liveStatuses): array {
            $userKey = (int) $session->user_id;
            $prices[$userKey] ??= $this->tariffService->pricePerKwhForUser($session->user);

            $liveStatus = null;
            if ($session->station) {
                $liveKey = $session->station_id . ':' . (int) ($session->ocpp_connector_id ?: 1);
                $liveStatuses[$liveKey] ??= $session->station->liveStatus((int) ($session->ocpp_connector_id ?: 1));
                $liveStatus = $liveStatuses[$liveKey];
            }

            return $this->presentForUser($session, $ensureInvoice($session), $prices[$userKey], $liveStatus);
        });
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
