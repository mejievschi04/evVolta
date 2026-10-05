<?php

namespace App\Console\Commands;

use App\Models\WalletRefund;
use App\Services\WalletService;
use Illuminate\Console\Command;
use RuntimeException;

class ResolveWalletRefund extends Command
{
    protected $signature = 'wallet:resolve-refund
        {refund? : ID-ul returului (fara argument: lista retururilor incerte)}
        {outcome? : completed (banii au ajuns pe card) sau failed (returul nu s-a facut)}
        {--provider-id= : ID-ul returului din portalul MAIB (pentru completed)}
        {--force : Nu cere confirmare}';

    protected $description = 'Listeaza sau inchide retururile MAIB ramase incerte (pending / needs_review)';

    public function handle(WalletService $walletService): int
    {
        if (! $this->argument('refund')) {
            return $this->listUncertain();
        }

        $refund = WalletRefund::query()->with('user:id,email')->find((int) $this->argument('refund'));

        if (! $refund) {
            $this->error('Returul nu a fost gasit.');

            return self::FAILURE;
        }

        $outcome = (string) $this->argument('outcome');

        if (! in_array($outcome, ['completed', 'failed'], true)) {
            $this->error('Rezultatul trebuie sa fie "completed" sau "failed".');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Retur #%d · %s · %.2f %s · status %s · alimentare #%d',
            $refund->id,
            $refund->user?->email ?? 'user #' . $refund->user_id,
            $refund->amount,
            $refund->currency,
            $refund->status,
            $refund->wallet_topup_id
        ));
        $this->line($outcome === 'completed'
            ? 'completed: banii au ajuns pe card; debitarea wallet-ului ramane.'
            : 'failed: returul NU s-a facut; suma revine in wallet-ul clientului.');

        if (! $this->option('force') && ! $this->confirm('Ai verificat in portalul MAIB si confirmi?')) {
            $this->warn('Anulat.');

            return self::FAILURE;
        }

        try {
            $resolved = $walletService->resolveUncertainRefund(
                $refund,
                $outcome === 'completed',
                $this->option('provider-id') ?: null,
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Returul #%d este acum %s.', $resolved->id, $resolved->status));

        return self::SUCCESS;
    }

    private function listUncertain(): int
    {
        $rows = WalletRefund::query()
            ->with('user:id,email')
            ->whereIn('status', ['pending', 'needs_review'])
            ->orderBy('id')
            ->get()
            ->map(fn (WalletRefund $refund) => [
                $refund->id,
                $refund->user?->email ?? '#' . $refund->user_id,
                number_format($refund->amount, 2) . ' ' . $refund->currency,
                $refund->status,
                $refund->wallet_topup_id,
                $refund->created_at?->toDateTimeString(),
            ]);

        if ($rows->isEmpty()) {
            $this->info('Nu exista retururi incerte.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Client', 'Suma', 'Status', 'Alimentare', 'Creat'], $rows->all());

        return self::SUCCESS;
    }
}
