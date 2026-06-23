<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Invoice;
use Carbon\Carbon;

class BillingService
{
    public function __construct(
        private readonly InvoiceIssuanceService $invoiceIssuanceService,
    ) {
    }

    public function createSessionInvoice(ChargingSession $session): ?Invoice
    {
        return $this->finalizeBillingForSession($session);
    }

    public function finalizeBillingForSession(ChargingSession $session): ?Invoice
    {
        $session->loadMissing('user');

        if (! $session->user?->usesCardPayment()) {
            return null;
        }

        $tariffService = app(TariffService::class);
        $pricePerKwh = $tariffService->pricePerKwhForUser($session->user);
        $charged = app(WalletService::class)->settleSession($session, $pricePerKwh);

        return $this->invoiceIssuanceService->createSessionInvoice($session->fresh(), $charged);
    }

    public function estimatedSessionCharge(ChargingSession $session, ?float $pricePerKwh = null): float
    {
        $session->loadMissing('user');
        $pricePerKwh ??= app(TariffService::class)->pricePerKwhForUser($session->user);
        $actualCost = round((float) $session->kwh_consumed * $pricePerKwh, 2);
        $budget = (float) ($session->charge_budget ?? 0);

        if ($budget > 0) {
            return round(min($actualCost, $budget), 2);
        }

        return $actualCost;
    }

    public function ensureSessionInvoice(ChargingSession $session): ?Invoice
    {
        $session->loadMissing('user');

        if (! $session->end_time || ! $session->user?->usesCardPayment()) {
            return null;
        }

        $existing = Invoice::query()
            ->where('source_session_id', $session->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        if ((float) $session->kwh_consumed <= 0) {
            return null;
        }

        return $this->invoiceIssuanceService->createSessionInvoice(
            $session->fresh(),
            $this->estimatedSessionCharge($session),
        );
    }

    public function generateMonthlyInvoices(?Carbon $targetMonth = null): int
    {
        return 0;
    }
}
