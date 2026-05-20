<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\Tariff;
use App\Models\User;
use Carbon\Carbon;

class BillingService
{
    public function createSessionInvoice(ChargingSession $session): Invoice
    {
        $session->loadMissing(['user', 'station']);

        $tariff = Tariff::query()->latest('id')->first();
        $pricePerKwh = $tariff?->price_per_kwh ?? (float) config('billing.price_per_kwh', 0.20);
        $totalKwh = (float) $session->kwh_consumed;
        $totalAmount = round($totalKwh * $pricePerKwh, 2);
        $invoiceNumber = 'EVS-' . $session->id;
        $month = $session->end_time?->format('Y-m') ?? $session->start_time?->format('Y-m') ?? now()->format('Y-m');

        return Invoice::query()->updateOrCreate(
            ['source_session_id' => $session->id],
            [
                'user_id' => $session->user_id,
                'month' => $month,
                'currency' => $session->user?->currency ?? 'MDL',
                'invoice_type' => 'session',
                'invoice_number' => $invoiceNumber,
                'period_start' => $session->start_time?->toDateString(),
                'period_end' => $session->end_time?->toDateString() ?? $session->start_time?->toDateString(),
                'total_kwh' => round($totalKwh, 2),
                'total_amount' => $totalAmount,
                'sessions_count' => 1,
                'status' => 'unpaid',
            ]
        );
    }

    public function generateMonthlyInvoices(?Carbon $targetMonth = null): int
    {
        $month = ($targetMonth ?? now()->subMonth())->startOfMonth();
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $tariff = Tariff::query()->latest('id')->first();
        $pricePerKwh = $tariff?->price_per_kwh ?? (float) config('billing.price_per_kwh', 0.20);

        $users = User::query()->get();
        $createdCount = 0;

        foreach ($users as $user) {
            $sessionsQuery = ChargingSession::query()
                ->where('user_id', $user->id)
                ->whereNotNull('end_time')
                ->whereBetween('end_time', [$from, $to]);

            $sessionsCount = (clone $sessionsQuery)->count();
            if ($sessionsCount <= 0) {
                continue;
            }

            $totalKwh = (float) (clone $sessionsQuery)->sum('kwh_consumed');

            if ($totalKwh <= 0) {
                continue;
            }

            Invoice::query()->updateOrCreate(
                ['user_id' => $user->id, 'month' => $month->format('Y-m'), 'invoice_type' => 'monthly'],
                [
                    'invoice_number' => 'EVM-' . $month->format('Ym') . '-' . $user->id,
                    'currency' => $user->currency ?? 'MDL',
                    'period_start' => $from->toDateString(),
                    'period_end' => $to->toDateString(),
                    'total_kwh' => round($totalKwh, 2),
                    'total_amount' => round($totalKwh * $pricePerKwh, 2),
                    'sessions_count' => $sessionsCount,
                    'status' => 'unpaid',
                ]
            );

            $createdCount++;
        }

        return $createdCount;
    }
}
