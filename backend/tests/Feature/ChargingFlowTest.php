<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChargingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_charging_stop_creates_invoice_when_session_has_consumption(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-16 09:20:00'));

        $user = User::query()->create([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'password' => Hash::make('password123'),
            'account_type' => User::ACCOUNT_TYPE_PERSONAL,
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

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-04-16 09:00:00'),
            'kwh_consumed' => 4,
        ]);

        try {
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
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_charging_stop_does_not_create_invoice_when_session_has_no_consumption(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-16 09:20:00'));

        $user = User::query()->create([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'password' => Hash::make('password123'),
            'account_type' => User::ACCOUNT_TYPE_PERSONAL,
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

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => Carbon::parse('2026-04-16 09:00:00'),
            'kwh_consumed' => 0,
            'meter_start_kwh' => 10,
        ]);

        $station->update(['meter_value_kwh' => 10]);

        try {
            $this->actingAs($user, 'api')
                ->postJson('/api/charging/stop', [
                    'station_id' => $station->id,
                ])
                ->assertOk()
                ->assertJsonPath('invoice', null);

            $this->assertDatabaseMissing('invoices', [
                'user_id' => $user->id,
            ]);

            $this->assertDatabaseHas('stations', [
                'id' => $station->id,
                'status' => Station::STATUS_AVAILABLE,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
