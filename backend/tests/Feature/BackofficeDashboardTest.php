<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackofficeDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_detailed_analytics(): void
    {
        $admin = $this->createAdminUser();
        $customer = $this->createAppUser([
            'account_type' => User::ACCOUNT_TYPE_CUSTOMER,
            'wallet_balance' => 120.5,
        ]);
        $station = Station::query()->create([
            'name' => 'Statia Nord',
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
        ]);

        ChargingSession::query()->create([
            'user_id' => $customer->id,
            'station_id' => $station->id,
            'start_time' => now()->subHours(2),
            'end_time' => now()->subHour(),
            'kwh_consumed' => 12.5,
        ]);

        Invoice::query()->create([
            'user_id' => $customer->id,
            'month' => now()->format('Y-m'),
            'currency' => 'MDL',
            'invoice_type' => 'session',
            'invoice_number' => 'EVS-PAID-1',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'total_kwh' => 8,
            'total_amount' => 40,
            'sessions_count' => 1,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        Invoice::query()->create([
            'user_id' => $customer->id,
            'month' => now()->format('Y-m'),
            'currency' => 'MDL',
            'invoice_type' => 'session',
            'invoice_number' => 'EVS-UNPAID-1',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'total_kwh' => 5,
            'total_amount' => 25,
            'sessions_count' => 1,
            'status' => 'unpaid',
        ]);

        $response = $this->withSession(['backoffice_user_id' => $admin->id])
            ->getJson('/backoffice/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'stats' => [
                    'users',
                    'sessionsToday',
                    'energyToday',
                    'energyMonth',
                    'revenuePaidMonth',
                    'sessionsMonth',
                ],
                'period' => ['from', 'to', 'granularity', 'days'],
                'analytics' => [
                    'currency',
                    'period',
                    'periodStats',
                    'energy' => ['today', 'week', 'month'],
                    'sessions' => ['today', 'week', 'month', 'closedMonth', 'active'],
                    'revenue' => ['invoicedTotal', 'paidTotal', 'unpaidTotal', 'paidToday', 'paidWeek', 'paidMonth'],
                    'users' => ['customer', 'personal', 'walletBalanceTotal'],
                    'wallet' => ['topupsToday', 'topupsMonth', 'topupsAllTime'],
                    'averages' => ['sessionKwh30d', 'sessionMinutes30d'],
                    'dailyTrend',
                    'topStationsMonth',
                    'topStations',
                ],
            ]);

        $payload = $response->json();

        $this->assertEquals(12.5, $payload['analytics']['energy']['today']);
        $this->assertEquals(65, $payload['analytics']['revenue']['invoicedTotal']);
        $this->assertEquals(40, $payload['analytics']['revenue']['paidTotal']);
        $this->assertEquals(25, $payload['analytics']['revenue']['unpaidTotal']);
        $this->assertEquals(120.5, $payload['analytics']['users']['walletBalanceTotal']);
        $this->assertCount(14, $payload['analytics']['dailyTrend']);
        $this->assertSame('Statia Nord', $payload['analytics']['topStationsMonth'][0]['station_name']);
    }

    public function test_dashboard_accepts_single_day_hourly_trend(): void
    {
        $admin = $this->createAdminUser();
        $day = now()->toDateString();

        $this->withSession(['backoffice_user_id' => $admin->id])
            ->getJson('/backoffice/dashboard?date=' . $day)
            ->assertOk()
            ->assertJsonPath('period.granularity', 'hour')
            ->assertJsonPath('period.from', $day)
            ->assertJsonPath('period.to', $day)
            ->assertJsonCount(24, 'analytics.dailyTrend');
    }
}
