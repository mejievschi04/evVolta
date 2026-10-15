<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Support\Collection;

class UsageStatisticsService
{
    public function __construct(
        private readonly WalletService $walletService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $pricePerKwh = $this->walletService->currentPricePerKwh($user);
        $currency = $user->currency ?? 'MDL';

        $completed = $this->completedSessions($user);

        $activeCount = ChargingSession::query()
            ->where('user_id', $user->id)
            ->whereNull('end_time')
            ->count();

        $now = LocalTime::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $currentMonthSessions = $completed->filter(
            fn (ChargingSession $session) => $session->end_time?->between($monthStart, $monthEnd)
        );

        return [
            'wallet_balance' => $this->walletService->balance($user),
            'currency' => $currency,
            'price_per_kwh' => $pricePerKwh,
            'lifetime' => [
                'sessions_count' => $completed->count(),
                'active_sessions' => $activeCount,
                'total_kwh' => round($completed->sum(fn (ChargingSession $session) => (float) $session->kwh_consumed), 2),
                'total_spent' => $this->totalSpent($completed),
            ],
            'current_month' => [
                'month' => $now->format('Y-m'),
                'sessions_count' => $currentMonthSessions->count(),
                'total_kwh' => round($currentMonthSessions->sum(fn (ChargingSession $session) => (float) $session->kwh_consumed), 2),
                'total_spent' => $this->totalSpent($currentMonthSessions),
            ],
            'monthly' => $this->buildMonthlyBreakdown($completed),
        ];
    }

    /**
     * @param  Collection<int, ChargingSession>  $completed
     * @return list<array{month: string, sessions_count: int, total_kwh: float, total_spent: float}>
     */
    private function buildMonthlyBreakdown(Collection $completed, int $limit = 12): array
    {
        return $completed
            ->groupBy(fn (ChargingSession $session) => LocalTime::of($session->end_time)?->format('Y-m'))
            ->sortKeysDesc()
            ->take($limit)
            ->map(function (Collection $sessions, string $month) {
                return [
                    'month' => $month,
                    'sessions_count' => $sessions->count(),
                    'total_kwh' => round($sessions->sum(fn (ChargingSession $session) => (float) $session->kwh_consumed), 2),
                    'total_spent' => $this->totalSpent($sessions),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Totals for the app's history screen, so it no longer needs every session to compute them.
     *
     * @return array{sessions: int, active: int, closed: int, total_kwh: float, total_spent: float, currency: string}
     */
    public function historySummary(User $user): array
    {
        $completed = $this->completedSessions($user);
        $active = ChargingSession::query()
            ->where('user_id', $user->id)
            ->whereNull('end_time')
            ->count();

        return [
            'sessions' => $completed->count() + $active,
            'active' => $active,
            'closed' => $completed->count(),
            'total_kwh' => round($completed->sum(fn (ChargingSession $session) => (float) $session->kwh_consumed), 3),
            'total_spent' => $this->totalSpent($completed),
            'currency' => $user->currency ?? 'MDL',
        ];
    }

    /**
     * @return Collection<int, ChargingSession>
     */
    private function completedSessions(User $user): Collection
    {
        return ChargingSession::query()
            ->with('invoice:id,source_session_id,total_amount')
            ->where('user_id', $user->id)
            ->whereNotNull('end_time')
            ->orderByDesc('end_time')
            ->get()
            ->each(fn (ChargingSession $session) => $session->setRelation('user', $user));
    }

    /**
     * Same amount the session list shows: the invoice when issued, otherwise the settled estimate
     * at the session's own (start-time) price.
     *
     * @param  Collection<int, ChargingSession>  $sessions
     */
    private function totalSpent(Collection $sessions): float
    {
        $billing = app(BillingService::class);

        return round($sessions->sum(
            fn (ChargingSession $session) => $session->invoice
                ? (float) $session->invoice->total_amount
                : $billing->estimatedSessionCharge($session)
        ), 2);
    }

}
