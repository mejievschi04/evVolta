<?php

namespace App\Console\Commands;

use App\Models\WalletTopup;
use App\Services\MaibPaymentService;
use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileWalletTopups extends Command
{
    protected $signature = 'wallet:reconcile-topups
        {--hours=48 : Verifica alimentarile pending create in ultimele N ore}
        {--min-age=3 : Ignora alimentarile mai noi de N minute (clientul inca plateste)}
        {--expire-after=24 : Marcheaza expirate alimentarile neplatite mai vechi de N ore}
        {--limit=50 : Numar maxim de alimentari verificate per rulare}';

    protected $description = 'Crediteaza alimentarile MAIB platite al caror callback nu a ajuns si le expira pe cele neplatite';

    // Daca starea la MAIB nu poate fi aflata nici dupa atatea zile, alimentarea se expira oricum.
    private const FORCE_EXPIRE_DAYS = 7;

    private const OUTCOME_PAID = 'paid';

    private const OUTCOME_UNPAID = 'unpaid';

    private const OUTCOME_UNKNOWN = 'unknown';

    // MAIB says paid but crediting failed: retried every run, never expired.
    private const OUTCOME_CREDIT_FAILED = 'credit_failed';

    public function handle(MaibPaymentService $maibPaymentService, WalletService $walletService): int
    {
        $maibReady = $maibPaymentService->isConfigured();
        if (! $maibReady) {
            $this->warn('MAIB nu este configurat; alimentarile MAIB se expira doar dupa '.self::FORCE_EXPIRE_DAYS.' zile.');
        }

        $expireCutoff = now()->subHours(max(1, (int) $this->option('expire-after')));
        $limit = max(1, (int) $this->option('limit'));

        $checked = 0;
        $credited = 0;

        if ($maibReady) {
            // Older ones are handled by the expiry pass below.
            $recent = $this->leastRecentlyChecked(
                WalletTopup::query()
                    ->where('status', 'pending')
                    ->where('payment_provider', 'maib')
                    ->whereNotNull('payment_session_id')
                    ->where('created_at', '>', max(
                        now()->subHours(max(1, (int) $this->option('hours'))),
                        $expireCutoff,
                    ))
                    ->where('created_at', '<=', now()->subMinutes(max(0, (int) $this->option('min-age'))))
            )->limit($limit)->get();

            foreach ($recent as $topup) {
                $checked++;
                if ($this->checkMaib($topup, $maibPaymentService, $walletService) === self::OUTCOME_PAID) {
                    $credited++;
                }
            }
        }

        $expired = 0;
        $forceCutoff = now()->subDays(self::FORCE_EXPIRE_DAYS);

        $stale = $this->leastRecentlyChecked(
            WalletTopup::query()
                ->where('status', 'pending')
                ->where('created_at', '<=', $expireCutoff)
        )->limit($limit)->get();

        foreach ($stale as $topup) {
            if ($topup->payment_provider === 'maib' && $topup->payment_session_id) {
                $checked++;
                $outcome = $maibReady
                    ? $this->checkMaib($topup, $maibPaymentService, $walletService)
                    : $this->markChecked($topup, self::OUTCOME_UNKNOWN);

                if ($outcome === self::OUTCOME_PAID) {
                    $credited++;

                    continue;
                }

                if ($outcome === self::OUTCOME_CREDIT_FAILED) {
                    continue;
                }

                // A paid topup must never be expired just because MAIB was unreachable for a while.
                if ($outcome === self::OUTCOME_UNKNOWN && $topup->created_at > $forceCutoff) {
                    continue;
                }
            }

            if ($this->expire($topup)) {
                $expired++;
            }
        }

        $this->info(sprintf(
            'Alimentari verificate: %d, creditate: %d, expirate: %d.',
            $checked,
            $credited,
            $expired,
        ));

        return self::SUCCESS;
    }

    /**
     * Never-checked first, then the longest-unchecked, so topups MAIB keeps failing on cannot starve the rest.
     */
    private function leastRecentlyChecked(Builder $query): Builder
    {
        return $query
            ->orderByRaw('last_checked_at is not null')
            ->orderBy('last_checked_at')
            ->orderBy('id');
    }

    private function checkMaib(
        WalletTopup $topup,
        MaibPaymentService $maibPaymentService,
        WalletService $walletService,
    ): string {
        try {
            $info = $maibPaymentService->getPaymentInfo((string) $topup->payment_session_id);
            $paid = $maibPaymentService->isCheckoutPaid($info);
        } catch (Throwable $exception) {
            Log::error('wallet.topup.reconcile_failed', [
                'topup_id' => $topup->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->markChecked($topup, self::OUTCOME_UNKNOWN);
        }

        if (! $paid) {
            return $this->markChecked($topup, self::OUTCOME_UNPAID);
        }

        try {
            // Aceeasi cale ca verificarea din aplicatie si callback-ul: idempotenta pe alimentare.
            $walletService->creditTopup(
                $topup,
                $topup->payment_session_id,
                $maibPaymentService->extractPaymentId($info),
                $maibPaymentService->extractPaymentMethod($info),
            );
        } catch (Throwable $exception) {
            Log::error('wallet.topup.credit_failed', [
                'topup_id' => $topup->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->markChecked($topup, self::OUTCOME_CREDIT_FAILED);
        }

        Log::warning('wallet.topup.reconciled_without_callback', [
            'topup_id' => $topup->id,
            'user_id' => $topup->user_id,
            'amount' => $topup->amount,
        ]);

        return $this->markChecked($topup, self::OUTCOME_PAID);
    }

    private function markChecked(WalletTopup $topup, string $outcome): string
    {
        // Query update: no model events, and no clobbering of a status a callback may have just set.
        WalletTopup::query()->whereKey($topup->id)->update(['last_checked_at' => now()]);

        return $outcome;
    }

    private function expire(WalletTopup $topup): bool
    {
        // Conditional update so a callback crediting it in the meantime is never overwritten.
        $updated = WalletTopup::query()
            ->whereKey($topup->id)
            ->where('status', 'pending')
            ->update(['status' => 'expired', 'updated_at' => now()]);

        if ($updated === 0) {
            return false;
        }

        Log::info('wallet.topup.expired', [
            'topup_id' => $topup->id,
            'user_id' => $topup->user_id,
            'amount' => $topup->amount,
        ]);

        return true;
    }
}
