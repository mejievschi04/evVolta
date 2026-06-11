<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChargingStopFinishingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ocpp.mode' => 'gateway']);
    }

    public function test_stop_finalizes_immediately_when_connector_is_finishing(): void
    {
        $user = User::query()->create([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'password' => Hash::make('password123'),
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chisinau',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                    2 => ['connectorId' => 2, 'status' => 'Finishing'],
                ],
            ],
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_transaction_id' => '12',
            'start_time' => now()->subMinutes(5),
            'meter_start_kwh' => 0.1,
            'kwh_consumed' => 0.708,
            'live_metrics' => [
                'energy_kwh' => 0.808,
                'sampled_at' => now()->toIso8601String(),
            ],
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/stop', [
                'station_id' => $station->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $session->refresh();
        $this->assertNotNull($session->end_time);
        $this->assertSame(0.708, (float) $session->kwh_consumed);
    }
}
