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
        $result = $payload['result'] ?? null;
        $signature = (string) ($payload['signature'] ?? '');

        if (! is_array($result)) {
            Log::warning('maib.callback.invalid_payload', ['payload' => $payload]);

            return response()->json(['message' => 'Payload invalid.'], 400);
        }

        if (! $maibPaymentService->verifyCallbackSignature($result, $signature)) {
            Log::warning('maib.callback.invalid_signature', [
                'payId' => $result['payId'] ?? null,
                'orderId' => $result['orderId'] ?? null,
            ]);

            return response()->json(['message' => 'Semnatura invalida.'], 403);
        }

        $status = (string) ($result['status'] ?? '');
        $payId = (string) ($result['payId'] ?? '');
        $orderId = (string) ($result['orderId'] ?? '');

        $topup = $maibPaymentService->resolveTopupFromOrderId($orderId);
        if (! $topup && $payId !== '') {
            $topup = WalletTopup::query()
                ->where('payment_provider', 'maib')
                ->where('payment_session_id', $payId)
                ->first();
        }

        if (! $topup) {
            Log::warning('maib.callback.topup_not_found', [
                'payId' => $payId,
                'orderId' => $orderId,
            ]);

            // Still 200 so MAIB stops retrying unknown/orphan callbacks.
            return response()->json(['received' => true, 'matched' => false]);
        }

        if ($status === 'OK') {
            $walletService->creditTopup(
                $topup,
                $payId !== '' ? $payId : $topup->payment_session_id,
                $result['rrn'] ?? null,
            );
        } else {
            Log::info('maib.callback.non_ok_status', [
                'topup_id' => $topup->id,
                'status' => $status,
                'statusCode' => $result['statusCode'] ?? null,
                'statusMessage' => $result['statusMessage'] ?? null,
            ]);
        }

        return response()->json(['received' => true, 'matched' => true]);
    }
}
