<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MaibPaymentService;
use App\Services\StripePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function config(
        Request $request,
        StripePaymentService $stripePaymentService,
        MaibPaymentService $maibPaymentService,
    ): JsonResponse {
        $user = $request->user();
        $provider = strtolower((string) config('services.payment.provider', 'maib'));

        $maibReady = $maibPaymentService->isConfigured();
        $stripeReady = $stripePaymentService->isConfigured();

        if ($provider === 'maib' && $maibReady) {
            $active = 'maib';
        } elseif ($provider === 'stripe' && $stripeReady) {
            $active = 'stripe';
        } elseif ($maibReady) {
            $active = 'maib';
        } elseif ($stripeReady) {
            $active = 'stripe';
        } else {
            $active = null;
        }

        return response()->json([
            'provider' => $active,
            'card_payments_enabled' => $user->usesCardPayment() && $active !== null,
            'account_type' => $user->account_type,
        ]);
    }
}
