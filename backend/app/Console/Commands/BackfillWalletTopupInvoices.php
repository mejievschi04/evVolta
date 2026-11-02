<?php

namespace App\Console\Commands;

use App\Models\WalletTopup;
use App\Services\InvoiceIssuanceService;
use Illuminate\Console\Command;

class BackfillWalletTopupInvoices extends Command
{
    protected $signature = 'invoices:backfill-wallet-topups
        {--limit=200 : Numar maxim de alimentari verificate}';

    protected $description = 'Emite facturi pentru alimentarile platite care n-au primit document fiscal';

    public function handle(InvoiceIssuanceService $invoices): int
    {
        $topups = WalletTopup::query()
            ->with('user')
            ->where('status', 'paid')
            ->whereDoesntHave('invoice')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $issued = 0;
        foreach ($topups as $topup) {
            if ($invoices->createWalletTopupInvoice($topup)) {
                $issued++;
            }
        }

        $this->info(sprintf(
            'Alimentari verificate: %d, facturi emise: %d.',
            $topups->count(),
            $issued,
        ));

        return self::SUCCESS;
    }
}
