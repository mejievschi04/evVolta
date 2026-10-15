<?php

namespace App\Console\Commands;

use App\Models\WalletTopup;
use App\Services\MaibPaymentService;
use Illuminate\Console\Command;
use Throwable;

class BackfillTopupPaymentMethods extends Command
{
    protected $signature = 'wallet:backfill-payment-methods
        {--limit=500 : Numar maxim de alimentari verificate}';

    protected $description = 'Completeaza metoda de plata (Card / MIA) pentru alimentarile platite inainte ca ea sa fie salvata';

    public function handle(MaibPaymentService $maibPaymentService): int
    {
        if (! $maibPaymentService->isConfigured()) {
            $this->warn('MAIB nu este configurat; nimic de verificat.');

            return self::SUCCESS;
        }

        $topups = WalletTopup::query()
            ->where('status', 'paid')
            ->where('payment_provider', 'maib')
            ->whereNull('payment_method')
            ->whereNotNull('payment_session_id')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $filled = 0;
        $failed = 0;

        foreach ($topups as $topup) {
            try {
                $method = $maibPaymentService->extractPaymentMethod(
                    $maibPaymentService->getPaymentInfo((string) $topup->payment_session_id)
                );
            } catch (Throwable $exception) {
                $failed++;
                $this->warn(sprintf('Alimentarea #%d: %s', $topup->id, $exception->getMessage()));

                continue;
            }

            if ($method === null) {
                continue;
            }

            WalletTopup::query()
                ->whereKey($topup->id)
                ->whereNull('payment_method')
                ->update(['payment_method' => $method]);
            $filled++;
        }

        $this->info(sprintf(
            'Alimentari verificate: %d, completate: %d, erori: %d.',
            $topups->count(),
            $filled,
            $failed,
        ));

        return self::SUCCESS;
    }
}
