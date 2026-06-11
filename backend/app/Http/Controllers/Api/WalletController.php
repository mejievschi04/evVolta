<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletTopup;
use App\Services\StripePaymentService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class WalletController extends Controller
{
    public function show(Request $request, WalletService $walletService): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'wallet_balance' => $walletService->balance($user),
            'currency' => $user->currency ?? 'MDL',
            'requires_prepaid' => $walletService->enabled() && $user->usesCardPayment(),
            'prepaid_wallet_enabled' => $walletService->enabled(),
            'charge_options' => $walletService->enabled() && $user->usesCardPayment()
                ? $walletService->chargeOptions()
                : null,
        ]);
    }

    public function createTopupCheckout(Request $request, StripePaymentService $stripePaymentService): JsonResponse
    {
        $user = $request->user();

        if (! $user->usesCardPayment()) {
            return response()->json([
                'message' => 'Contul personal nu foloseste alimentare prepay.',
            ], 422);
        }

        $data = $request->validate([
            'amount' => 'required|numeric|min:10|max:50000',
        ]);

        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => round((float) $data['amount'], 2),
            'currency' => $user->currency ?? 'MDL',
            'status' => 'pending',
        ]);

        try {
            $checkout = $stripePaymentService->createWalletTopupSession($topup, $user);
        } catch (RuntimeException $exception) {
            $topup->delete();

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $topup->update([
            'payment_session_id' => $checkout['id'],
            'payment_provider' => 'stripe',
        ]);

        return response()->json([
            'topup_id' => $topup->id,
            'checkout_url' => $checkout['url'],
            'payment_session_id' => $checkout['id'],
        ]);
    }

    public function localTopup(Request $request, WalletService $walletService): JsonResponse
    {
        abort_unless(app()->environment('local'), 404);
        abort_unless($walletService->enabled(), 404);

        $user = $request->user();

        if (! $user->usesCardPayment()) {
            return response()->json([
                'message' => 'Contul personal nu foloseste alimentare prepay.',
            ], 422);
        }

        $amount = 500;

        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => $amount,
            'currency' => $user->currency ?? 'MDL',
            'status' => 'pending',
            'payment_provider' => 'local',
        ]);

        $walletService->creditTopup($topup);

        return response()->json([
            'wallet_balance' => $walletService->balance($user->fresh()),
            'currency' => $user->currency ?? 'MDL',
            'credited' => $amount,
        ]);
    }
}
