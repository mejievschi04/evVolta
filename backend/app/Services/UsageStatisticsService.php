<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\User;
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
        $pricePerKwh = $this->walletService->currentPricePerKwh();
        $currency = $user->currency ?? 'MDL';

        $completed = ChargingSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('end_time')
            ->orderByDesc('end_time')
            ->get();

        $activeCount = ChargingSession::query()
            ->where('user_id', $user->id)
            ->whereNull('end_time')
            ->count();

        $now = now();
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
                'total_spent' => round($completed->sum(fn (ChargingSession $session) => $this->sessionSpent($session, $pricePerKwh)), 2),
            ],
            'current_month' => [
                'month' => $now->format('Y-m'),
                'sessions_count' => $currentMonthSessions->count(),
                'total_kwh' => round($currentMonthSessions->sum(fn (ChargingSession $session) => (float) $session->kwh_consumed), 2),
                'total_spent' => round($currentMonthSessions->sum(fn (ChargingSession $session) => $this->sessionSpent($session, $pricePerKwh)), 2),
            ],
            'monthly' => $this->buildMonthlyBreakdown($completed, $pricePerKwh),
        ];
    }

    /**
     * @param  Collection<int, ChargingSession>  $completed
     * @return list<array{month: string, sessions_count: int, total_kwh: float, total_spent: float}>
     */
    private function buildMonthlyBreakdown(Collection $completed, float $pricePerKwh, int $limit = 12): array
    {
        return $completed
            ->groupBy(fn (ChargingSession $session) => $session->end_time?->format('Y-m'))
            ->sortKeysDesc()
            ->take($limit)
            ->map(function (Collection $sessions, string $month) use ($pricePerKwh) {
                return [
                    'month' => $month,
                    'sessions_count' => $sessions->count(),
                    'total_kwh' => round($sessions->sum(fn (ChargingSession $session) => (float) $session->kwh_consumed), 2),
                    'total_spent' => round($sessions->sum(fn (ChargingSession $session) => $this->sessionSpent($session, $pricePerKwh)), 2),
                ];
            })
            ->values()
            ->all();
    }

    private function sessionSpent(ChargingSession $session, float $pricePerKwh): float
    {
        $actualCost = round((float) $session->kwh_consumed * $pricePerKwh, 2);
        $budget = (float) ($session->charge_budget ?? 0);

        if ($budget > 0) {
            return round(min($actualCost, $budget), 2);
        }

        return $actualCost;
    }
}
