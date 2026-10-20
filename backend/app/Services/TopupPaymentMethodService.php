<?php

namespace App\Services;

use App\Models\WalletTopup;
use Illuminate\Support\Facades\Log;
use Throwable;

class TopupPaymentMethodService
{
    public function __construct(private readonly MaibPaymentService $maib)
    {
    }

    /** Recover payment metadata only; never credit the wallet again. */
    public function fillMissing(WalletTopup $topup): bool
    {
        if (filled($topup->payment_method) || $topup->status !== 'paid'
            || $topup->payment_provider !== 'maib' || blank($topup->payment_session_id)
            || ! $this->maib->isConfigured()) {
            return false;
        }

        try {
            $method = $this->maib->extractPaymentMethod(
                $this->maib->getPaymentInfo((string) $topup->payment_session_id)
            );
            if ($method === null) {
                return false;
            }

            $updated = WalletTopup::query()->whereKey($topup->id)
                ->where(fn ($query) => $query->whereNull('payment_method')->orWhereRaw("TRIM(payment_method) = ''"))
                ->update(['payment_method' => $method]);
            $topup->refresh();

            return $updated > 0;
        } catch (Throwable $exception) {
            Log::warning('wallet.topup.payment_method_unavailable', [
                'topup_id' => $topup->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
