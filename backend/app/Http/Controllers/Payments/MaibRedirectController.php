<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
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

        return view('payments.stripe-return', [
            'title' => $title,
            'status' => $status,
            'invoiceId' => 0,
            'deepLink' => $deepLink,
        ]);
    }
}
