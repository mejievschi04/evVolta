<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StripeRedirectController extends Controller
{
    public function success(Request $request): View
    {
        return $this->renderReturnPage($request, 'success', 'Plata a fost finalizata');
    }

    public function cancel(Request $request): View
    {
        return $this->renderReturnPage($request, 'cancel', 'Plata a fost oprita');
    }

    private function renderReturnPage(Request $request, string $status, string $title): View
    {
        $invoiceId = (int) $request->query('invoice_id', 0);
        $walletTopupId = (int) $request->query('wallet_topup_id', 0);
        $scheme = config('services.mobile.scheme', 'voltaev');

        if ($walletTopupId > 0) {
            $deepLink = sprintf('%s://pay/%s?wallet_topup_id=%d', $scheme, $status, $walletTopupId);
        } elseif ($invoiceId > 0) {
            $deepLink = sprintf('%s://pay/%s?invoice_id=%d', $scheme, $status, $invoiceId);
        } else {
            $deepLink = sprintf('%s://charge', $scheme);
        }

        return view('payments.stripe-return', [
            'title' => $title,
            'status' => $status,
            'invoiceId' => $invoiceId,
            'deepLink' => $deepLink,
        ]);
    }
}
