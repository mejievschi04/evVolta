<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletTopup;
use App\Services\MaibPaymentService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MaibCallbackController extends Controller
{
    public function handle(
        Request $request,
        MaibPaymentService $maibPaymentService,
        WalletService $walletService,
    ): JsonResponse {
        $payload = $request->all();

        if ($payload === []) {
            Log::warning('maib.callback.invalid_payload', ['payload' => $payload]);

            return response()->json(['message' => 'Payload invalid.'], 400);
        }

        if (! $maibPaymentService->verifyCallbackRequest($request)) {
            Log::warning('maib.callback.invalid_signature', [
                'checkoutId' => $payload['checkoutId'] ?? null,
                'orderId' => $payload['orderId'] ?? null,
                'paymentId' => $payload['paymentId'] ?? null,
            ]);

            return response()->json(['message' => 'Semnatura invalida.'], 403);
        }

        $checkoutId = (string) ($payload['checkoutId'] ?? '');
        $orderId = (string) ($payload['orderId'] ?? '');
        $paymentId = (string) ($payload['paymentId'] ?? '');
        $paymentStatus = (string) ($payload['paymentStatus'] ?? '');
        $processingStatus = (string) ($payload['processingStatus'] ?? '');

        $topup = $maibPaymentService->resolveTopupFromOrderId($orderId);
        if (! $topup && $checkoutId !== '') {
            $topup = WalletTopup::query()
                ->where('payment_provider', 'maib')
                ->where('payment_session_id', $checkoutId)
                ->first();
        }
        if (! $topup && $paymentId !== '') {
            $topup = WalletTopup::query()
                ->where('payment_provider', 'maib')
                ->where('payment_session_id', $paymentId)
                ->first();
        }

        if (! $topup) {
            Log::warning('maib.callback.topup_not_found', [
                'checkoutId' => $checkoutId,
                'paymentId' => $paymentId,
                'orderId' => $orderId,
            ]);

            // Still 200 so MAIB stops retrying unknown/orphan callbacks.
            return response()->json(['received' => true, 'matched' => false]);
        }

        $paid = strcasecmp($paymentStatus, 'Executed') === 0
            || strcasecmp($processingStatus, 'OK') === 0;

        if ($paid) {
            $walletService->creditTopup(
                $topup,
                $paymentId !== '' ? $paymentId : ($checkoutId !== '' ? $checkoutId : $topup->payment_session_id),
                $payload['retrievalReferenceNumber'] ?? $payload['referenceNumber'] ?? null,
            );
        } else {
            Log::info('maib.callback.non_ok_status', [
                'topup_id' => $topup->id,
                'paymentStatus' => $paymentStatus,
                'processingStatus' => $processingStatus,
                'processingStatusCode' => $payload['processingStatusCode'] ?? null,
            ]);
        }

        return response()->json(['received' => true, 'matched' => true]);
    }
}
