<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MaibPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function config(
        Request $request,
        MaibPaymentService $maibPaymentService,
    ): JsonResponse {
        $user = $request->user();
        $active = $maibPaymentService->isConfigured() ? 'maib' : null;

        return response()->json([
            'provider' => $active,
            'card_payments_enabled' => $user->usesCardPayment() && $active !== null,
            'account_type' => $user->account_type,
        ]);
    }
}
