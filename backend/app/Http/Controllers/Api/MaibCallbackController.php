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

        $paid = strcasecmp($paymentStatus, 'Executed') === 0;

        if ($paid) {
            $callbackAmount = null;
            if (isset($payload['paymentAmount']) && is_numeric($payload['paymentAmount'])) {
                $callbackAmount = round((float) $payload['paymentAmount'], 2);
            } elseif (isset($payload['amount']) && is_numeric($payload['amount'])) {
                $callbackAmount = round((float) $payload['amount'], 2);
            }

            $expectedAmount = round((float) $topup->amount, 2);
            if ($callbackAmount !== null && abs($callbackAmount - $expectedAmount) > 0.009) {
                Log::warning('maib.callback.amount_mismatch', [
                    'topup_id' => $topup->id,
                    'expected' => $expectedAmount,
                    'callback_amount' => $callbackAmount,
                    'checkoutId' => $checkoutId,
                    'paymentId' => $paymentId,
                ]);

                return response()->json([
                    'received' => true,
                    'matched' => true,
                    'credited' => false,
                    'reason' => 'amount_mismatch',
                ]);
            }

            // Keep checkoutId in payment_session_id; store MAIB paymentId in payment_intent_id.
            $walletService->creditTopup(
                $topup,
                $checkoutId !== '' ? $checkoutId : null,
                $paymentId !== '' ? $paymentId : null,
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
