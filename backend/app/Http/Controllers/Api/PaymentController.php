<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StripePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function config(Request $request, StripePaymentService $stripePaymentService): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'provider' => 'stripe',
            'card_payments_enabled' => $user->usesCardPayment() && $stripePaymentService->isConfigured(),
            'account_type' => $user->account_type,
        ]);
    }
}
