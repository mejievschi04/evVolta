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

    public function generateMonthlyInvoices(?Carbon $targetMonth = null): int
    {
        return 0;
    }
}
