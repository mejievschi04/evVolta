<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletTopup;
use App\Services\MaibPaymentService;
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
                ? $walletService->chargeOptions($user)
                : null,
        ]);
    }

    public function indexTopups(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->usesCardPayment()) {
            return response()->json([
                'topups' => [],
                'summary' => [
                    'total_credited' => 0,
                    'paid_count' => 0,
                    'pending_count' => 0,
                    'currency' => $user->currency ?? 'MDL',
                ],
            ]);
        }

        $topups = WalletTopup::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(50)
            ->get([
                'id',
                'amount',
                'amount_refunded',
                'currency',
                'status',
                'payment_provider',
                'paid_at',
                'created_at',
            ]);

        $paid = $topups->where('status', 'paid');

        return response()->json([
            'topups' => $topups,
            'summary' => [
                'total_credited' => round((float) $paid->sum('amount'), 2),
                'paid_count' => $paid->count(),
                'pending_count' => $topups->where('status', 'pending')->count(),
                'currency' => $user->currency ?? 'MDL',
            ],
        ]);
    }

    public function createTopupCheckout(
        Request $request,
        StripePaymentService $stripePaymentService,
        MaibPaymentService $maibPaymentService,
    ): JsonResponse {
        $user = $request->user();

        if (! $user->usesCardPayment()) {
            return response()->json([
                'message' => 'Alimentarea wallet nu este disponibila pentru acest cont.',
            ], 422);
        }

        $data = $request->validate([
            'amount' => 'required|numeric|min:10|max:50000',
        ]);

        $provider = $this->resolvePaymentProvider($stripePaymentService, $maibPaymentService);

        if ($provider === null) {
            return response()->json([
                'message' => 'Plata cu cardul nu este configurata. Contacteaza administratorul.',
            ], 422);
        }

        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => round((float) $data['amount'], 2),
            'currency' => $user->currency ?? 'MDL',
            'status' => 'pending',
            'payment_provider' => $provider,
        ]);

        try {
            if ($provider === 'maib') {
                $checkout = $maibPaymentService->createWalletTopupPayment(
                    $topup,
                    $user,
                    (string) $request->ip()
                );
            } else {
                $checkout = $stripePaymentService->createWalletTopupSession($topup, $user);
            }
        } catch (RuntimeException $exception) {
            $topup->delete();

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $topup->update([
            'payment_session_id' => $checkout['id'],
            'payment_provider' => $provider,
        ]);

        return response()->json([
            'topup_id' => $topup->id,
            'checkout_url' => $checkout['url'],
            'payment_session_id' => $checkout['id'],
            'payment_provider' => $provider,
        ]);
    }

    public function verifyTopupPayment(
        Request $request,
        WalletTopup $topup,
        StripePaymentService $stripePaymentService,
        MaibPaymentService $maibPaymentService,
        WalletService $walletService,
    ): JsonResponse {
        $user = $request->user();

        if ((int) $topup->user_id !== (int) $user->id) {
            abort(403, 'Nu ai acces la aceasta alimentare.');
        }

        if ($topup->status === 'paid') {
            return response()->json([
                'message' => 'Alimentarea este deja confirmata.',
                'payment_status' => 'paid',
                'wallet_balance' => $walletService->balance($user),
                'topup' => $topup->fresh(),
            ]);
        }

        if (! $topup->payment_session_id) {
            return response()->json([
                'message' => 'Alimentarea nu are o sesiune de plata asociata.',
            ], 422);
        }

        if ($topup->payment_provider === 'maib') {
            return $this->verifyMaibTopup($topup, $user, $maibPaymentService, $walletService);
        }

        try {
            $session = $stripePaymentService->retrieveCheckoutSession($topup->payment_session_id);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if (($session['payment_status'] ?? null) === 'paid') {
            $walletService->creditTopup(
                $topup,
                $session['id'] ?? $topup->payment_session_id,
                $session['payment_intent'] ?? null,
            );
        }

        $topup = $topup->fresh();
        $user = $user->fresh();

        return response()->json([
            'message' => $topup->status === 'paid'
                ? 'Plata a fost confirmata.'
                : 'Plata este inca in curs de procesare.',
            'payment_status' => $session['payment_status'] ?? 'unpaid',
            'session_status' => $session['status'] ?? 'open',
            'wallet_balance' => $walletService->balance($user),
            'topup' => $topup,
        ]);
    }

    private function verifyMaibTopup(
        WalletTopup $topup,
        User $user,
        MaibPaymentService $maibPaymentService,
        WalletService $walletService,
    ): JsonResponse {
        try {
            $info = $maibPaymentService->getPaymentInfo((string) $topup->payment_session_id);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $status = (string) ($info['status'] ?? '');
        $paid = $maibPaymentService->isCheckoutPaid($info);
        $paymentStatus = $paid ? 'paid' : 'unpaid';

        if ($paid) {
            // Keep checkoutId in payment_session_id; store MAIB paymentId in payment_intent_id.
            $walletService->creditTopup(
                $topup,
                $topup->payment_session_id,
                $maibPaymentService->extractPaymentId($info),
            );
        }

        $topup = $topup->fresh();
        $user = $user->fresh();

        return response()->json([
            'message' => $topup->status === 'paid'
                ? 'Plata a fost confirmata.'
                : 'Plata este inca in curs de procesare.',
            'payment_status' => $paymentStatus,
            'session_status' => $status !== '' ? $status : 'pending',
            'wallet_balance' => $walletService->balance($user),
            'topup' => $topup,
        ]);
    }

    private function resolvePaymentProvider(
        StripePaymentService $stripePaymentService,
        MaibPaymentService $maibPaymentService,
    ): ?string {
        $configured = strtolower((string) config('services.payment.provider', 'maib'));

        if ($configured === 'maib' && $maibPaymentService->isConfigured()) {
            return 'maib';
        }

        if ($configured === 'stripe' && $stripePaymentService->isConfigured()) {
            return 'stripe';
        }

        if ($maibPaymentService->isConfigured()) {
            return 'maib';
        }

        if ($stripePaymentService->isConfigured()) {
            return 'stripe';
        }

        return null;
    }
}
