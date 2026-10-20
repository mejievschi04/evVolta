<?php

namespace App\Console\Commands;

use App\Models\WalletTopup;
use App\Services\MaibPaymentService;
use App\Services\TopupPaymentMethodService;
use Illuminate\Console\Command;

class BackfillTopupPaymentMethods extends Command
{
    protected $signature = 'wallet:backfill-payment-methods
        {--limit=500 : Numar maxim de alimentari verificate}';

    protected $description = 'Completeaza metoda de plata (Card / MIA) pentru alimentarile platite inainte ca ea sa fie salvata';

    public function handle(MaibPaymentService $maibPaymentService, TopupPaymentMethodService $methods): int
    {
        if (! $maibPaymentService->isConfigured()) {
            $this->warn('MAIB nu este configurat; nimic de verificat.');

            return self::SUCCESS;
        }

        $topups = WalletTopup::query()
            ->where('status', 'paid')
            ->where('payment_provider', 'maib')
            ->where(fn ($query) => $query->whereNull('payment_method')->orWhereRaw("TRIM(payment_method) = ''"))
            ->whereNotNull('payment_session_id')
            ->orderByRaw('last_checked_at is not null')
            ->orderBy('last_checked_at')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $filled = 0;
        foreach ($topups as $topup) {
            if ($methods->fillMissing($topup)) {
                $filled++;
            }
            // Rotate unresolved records so they cannot starve later payments.
            WalletTopup::query()->whereKey($topup->id)->update(['last_checked_at' => now()]);
        }

        $this->info(sprintf(
            'Alimentari verificate: %d, completate: %d, ramase fara metoda: %d.',
            $topups->count(), $filled, $topups->count() - $filled,
        ));

        return self::SUCCESS;
    }
}
