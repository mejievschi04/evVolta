<?php

namespace App\Console\Commands;

use App\Models\WalletTopup;
use App\Services\MaibPaymentService;
use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileWalletTopups extends Command
{
    protected $signature = 'wallet:reconcile-topups
        {--hours=48 : Verifica alimentarile pending create in ultimele N ore}
        {--min-age=3 : Ignora alimentarile mai noi de N minute (clientul inca plateste)}
        {--limit=50 : Numar maxim de alimentari verificate per rulare}';

    protected $description = 'Crediteaza alimentarile MAIB platite al caror callback nu a ajuns';

    public function handle(MaibPaymentService $maibPaymentService, WalletService $walletService): int
    {
        if (! $maibPaymentService->isConfigured()) {
            $this->warn('MAIB nu este configurat; nimic de verificat.');

            return self::SUCCESS;
        }

        $topups = WalletTopup::query()
            ->where('status', 'pending')
            ->where('payment_provider', 'maib')
            ->whereNotNull('payment_session_id')
            ->where('created_at', '>=', now()->subHours(max(1, (int) $this->option('hours'))))
            ->where('created_at', '<=', now()->subMinutes(max(0, (int) $this->option('min-age'))))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $credited = 0;

        foreach ($topups as $topup) {
            try {
                $info = $maibPaymentService->getPaymentInfo((string) $topup->payment_session_id);

                if (! $maibPaymentService->isCheckoutPaid($info)) {
                    continue;
                }

                // Aceeasi cale ca verificarea din aplicatie si callback-ul: idempotenta pe alimentare.
                $walletService->creditTopup(
                    $topup,
                    $topup->payment_session_id,
                    $maibPaymentService->extractPaymentId($info),
                );

                $credited++;
                Log::warning('wallet.topup.reconciled_without_callback', [
                    'topup_id' => $topup->id,
                    'user_id' => $topup->user_id,
                    'amount' => $topup->amount,
                ]);
            } catch (Throwable $exception) {
                Log::error('wallet.topup.reconcile_failed', [
                    'topup_id' => $topup->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->info(sprintf('Alimentari verificate: %d, creditate: %d.', $topups->count(), $credited));

        return self::SUCCESS;
    }
}
