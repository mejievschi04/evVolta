<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\Tariff;
use App\Models\User;
use Carbon\Carbon;

class BillingService
{
    public function generateMonthlyInvoices(?Carbon $targetMonth = null): int
    {
        $month = ($targetMonth ?? now()->subMonth())->startOfMonth();
        $from = $month->copy();
        $to = $month->copy()->endOfMonth();

        $tariff = Tariff::query()->latest('id')->first();
        $pricePerKwh = $tariff?->price_per_kwh ?? (float) config('billing.price_per_kwh', 0.2);

        $users = User::query()->get();
        $createdCount = 0;

        foreach ($users as $user) {
            $totalKwh = (float) ChargingSession::query()
                ->where('user_id', $user->id)
                ->whereBetween('start_time', [$from, $to])
                ->sum('kwh_consumed');

            if ($totalKwh <= 0) {
                continue;
            }

            Invoice::query()->updateOrCreate(
                ['user_id' => $user->id, 'month' => $month->format('Y-m')],
                [
                    'total_kwh' => round($totalKwh, 2),
                    'total_amount' => round($totalKwh * $pricePerKwh, 2),
                    'status' => 'unpaid',
                ]
            );

            $createdCount++;
        }

        return $createdCount;
    }
}
