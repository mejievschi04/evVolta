<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\WalletTopup;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaibRedirectController extends Controller
{
    public function success(Request $request): View
    {
        return $this->renderReturnPage($request, 'success', 'Plata a fost finalizata');
    }

    public function fail(Request $request): View
    {
        return $this->renderReturnPage($request, 'cancel', 'Plata a esuat');
    }

    private function renderReturnPage(Request $request, string $status, string $title): View
    {
        $walletTopupId = (int) $request->query('wallet_topup_id', 0);
        $payId = trim((string) $request->query('payId', ''));
        $scheme = config('services.mobile.scheme', 'vcharge');

        if ($walletTopupId > 0) {
            $deepLink = sprintf(
                '%s://pay/%s?wallet_topup_id=%d%s',
                $scheme,
                $status,
                $walletTopupId,
                $payId !== '' ? '&payId='.rawurlencode($payId) : ''
            );
        } else {
            $deepLink = sprintf('%s://charge', $scheme);
        }

        $topup = $walletTopupId > 0
            ? WalletTopup::query()->find($walletTopupId)
            : null;

        $payment = null;
        if ($topup) {
            $paidAt = $topup->paid_at ?: ($status === 'success' ? now() : $topup->updated_at);
            $payment = [
                'order_number' => 'wallet-topup-'.$topup->id,
                'description' => 'Alimentare sold V CHARGE × 1',
                'amount' => round((float) $topup->amount, 2),
                'currency' => $topup->currency ?: 'MDL',
                'paid_at' => $paidAt?->format('d.m.Y H:i'),
                'company_name' => (string) config('legal.company_name', 'Volta SRL'),
                'app_name' => (string) config('legal.app_name', 'V CHARGE'),
            ];
        }

        return view('payments.payment-return', [
            'title' => $title,
            'status' => $status,
            'invoiceId' => 0,
            'deepLink' => $deepLink,
            'payment' => $payment,
        ]);
    }
}
