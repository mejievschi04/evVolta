<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\Tariff;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChargingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_charging_stop_creates_invoice_when_session_has_consumption(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-16 09:20:00'));

        $user = $this->createPersonalUser([
            'email' => 'driver@example.test',
            'wallet_balance' => 500,
        ]);

        $station = Station::query()->create([
            'name' => 'Depot C1',
            'location' => 'Private Depot',
            'status' => Station::STATUS_CHARGING,
            'qr_code' => 'station:depot-c1',
        ]);

        Tariff::query()->create([
            'price_per_kwh' => 0.50,
        ]);

        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-04-16 09:00:00'),
            'kwh_consumed' => 4,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/stop', [
                'station_id' => $station->id,
            ])
            ->assertOk()
            ->assertJsonPath('invoice.invoice_type', 'session');

        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'invoice_type' => 'session',
        ]);

        $this->assertDatabaseHas('stations', [
            'id' => $station->id,
            'status' => Station::STATUS_AVAILABLE,
        ]);

        Carbon::setTestNow();
    }

    public function test_charging_stop_does_not_create_invoice_when_session_has_no_consumption(): void
    {
        config(['billing.prepaid_wallet_enabled' => false]);

        $user = $this->createPersonalUser([
            'email' => 'driver-zero@example.test',
            'wallet_balance' => 500,
        ]);

        $station = Station::query()->create([
            'name' => 'Depot C2',
            'location' => 'Private Depot',
            'status' => Station::STATUS_CHARGING,
            'qr_code' => 'station:depot-c2',
        ]);

        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => now()->subMinutes(5),
            'kwh_consumed' => 0,
            'meter_start_kwh' => 10,
            'meter_stop_kwh' => 10,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/stop', [
                'station_id' => $station->id,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('invoices', [
            'user_id' => $user->id,
        ]);
    }
}
