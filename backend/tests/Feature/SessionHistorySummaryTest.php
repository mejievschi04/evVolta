<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\Station;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionHistorySummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_limit_is_opt_in_and_summary_covers_whole_history(): void
    {
        Tariff::query()->create(['price_per_kwh' => 5.00, 'personal_price_per_kwh' => 5.00]);
        $user = $this->createAppUser();
        $station = Station::query()->create(['name' => 'VOLTA H', 'location' => 'Chisinau', 'status' => 'available']);

        foreach (range(1, 4) as $i) {
            $session = ChargingSession::query()->create([
                'user_id' => $user->id,
                'station_id' => $station->id,
                'ocpp_connector_id' => 1,
                'start_time' => now()->subDays(30 - $i),
                'end_time' => now()->subDays(30 - $i)->addHour(),
                'kwh_consumed' => 2,
                'price_per_kwh' => 5.00,
            ]);

            Invoice::query()->create([
                'user_id' => $user->id,
                'source_session_id' => $session->id,
                'invoice_type' => 'session',
                'invoice_number' => 'H-'.$i,
                'month' => now()->format('Y-m'),
                'currency' => 'MDL',
                'period_start' => now()->toDateString(),
                'period_end' => now()->toDateString(),
                'total_kwh' => 2,
                'total_amount' => 10,
                'sessions_count' => 1,
                'status' => 'paid',
            ]);
        }

        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        $this->actingAs($user, 'api')->getJson('/api/sessions')->assertOk()->assertJsonCount(5);
        $this->actingAs($user, 'api')->getJson('/api/sessions?limit=2')->assertOk()->assertJsonCount(2);

        $this->actingAs($user, 'api')->getJson('/api/sessions/summary')
            ->assertOk()
            ->assertJsonPath('sessions', 5)
            ->assertJsonPath('active', 1)
            ->assertJsonPath('closed', 4)
            ->assertJsonPath('total_kwh', 8)
            ->assertJsonPath('total_spent', 40)
            ->assertJsonPath('currency', 'MDL');
    }
}
