<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletTopup;
use App\Services\AuditLogService;
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
        $devTopupEnabled = $walletService->devTopupEnabled()
            && $walletService->enabled()
            && $user->usesCardPayment();

        return response()->json([
            'wallet_balance' => $walletService->balance($user),
            'currency' => $user->currency ?? 'MDL',
            'requires_prepaid' => $walletService->enabled() && $user->usesCardPayment(),
            'prepaid_wallet_enabled' => $walletService->enabled(),
            'dev_topup_enabled' => $devTopupEnabled,
            'dev_topup_max_amount' => $devTopupEnabled ? $walletService->devTopupMaxAmount() : null,
            'dev_topup_daily_remaining' => $devTopupEnabled
                ? round(max(0, $walletService->devTopupDailyLimit() - $walletService->devTopupDailyCredited($user)), 2)
                : null,
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

    public function createTopupCheckout(Request $request, StripePaymentService $stripePaymentService): JsonResponse
    {
        $user = $request->user();

        if (! $user->usesCardPayment()) {
            return response()->json([
                'message' => 'Alimentarea wallet nu este disponibila pentru acest cont.',
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

    public function localTopup(
        Request $request,
        WalletService $walletService,
        AuditLogService $auditLogService,
    ): JsonResponse {
        abort_unless($walletService->devTopupEnabled() && $walletService->enabled(), 404);

        $user = $request->user();
        $maxAmount = $walletService->devTopupMaxAmount();

        $data = $request->validate([
            'amount' => 'nullable|numeric|min:'.WalletService::MIN_BUDGET_AMOUNT.'|max:'.$maxAmount,
        ]);

        $amount = isset($data['amount'])
            ? round((float) $data['amount'], 2)
            : min(500.0, $maxAmount);

        try {
            $walletService->assertDevTopupAllowed($user, $amount);
        } catch (RuntimeException $exception) {
            if ((int) $exception->getCode() === 404) {
                abort(404);
            }

            return response()->json([
                'message' => $exception->getMessage(),
            ], (int) ($exception->getCode() ?: 422));
        }

        $topup = WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => $amount,
            'currency' => $user->currency ?? 'MDL',
            'status' => 'pending',
            'payment_provider' => 'local',
        ]);

        $walletService->creditTopup($topup);

        $auditLogService->record(
            action: 'wallet.dev_topup',
            actor: $user,
            subjectType: WalletTopup::class,
            subjectId: $topup->id,
            metadata: [
                'credited' => $amount,
                'wallet_balance' => $walletService->balance($user->fresh()),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        );

        return response()->json([
            'wallet_balance' => $walletService->balance($user->fresh()),
            'currency' => $user->currency ?? 'MDL',
            'credited' => $amount,
        ]);
    }

    public function verifyTopupPayment(
        Request $request,
        WalletTopup $topup,
        StripePaymentService $stripePaymentService,
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
                'message' => 'Alimentarea nu are o sesiune Stripe asociata.',
            ], 422);
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
}
