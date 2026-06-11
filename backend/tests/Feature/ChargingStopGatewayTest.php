<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChargingStopGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ocpp.mode' => 'gateway',
        ]);
    }

    public function test_gateway_stop_queues_remote_command_without_closing_session(): void
    {
        $user = User::query()->create([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'password' => Hash::make('password123'),
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chișinău',
            'status' => Station::STATUS_CHARGING,
            'qr_code' => 'station:volta-1',
            'ocpp_identity' => 'volta-1',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'meter_value_kwh' => 12.5,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => now()->subMinutes(10),
            'meter_start_kwh' => 10.2,
            'kwh_consumed' => 2.3,
            'ocpp_transaction_id' => '42',
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/stop', [
                'station_id' => $station->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'stopping')
            ->assertJsonPath('session.id', $session->id);

        $this->assertDatabaseHas('charging_sessions', [
            'id' => $session->id,
            'end_time' => null,
        ]);

        $this->assertDatabaseHas('ocpp_commands', [
            'station_id' => $station->id,
            'charging_session_id' => $session->id,
            'action' => 'RemoteStopTransaction',
        ]);
    }

    public function test_gateway_stop_without_transaction_id_queues_remote_command(): void
    {
        $user = User::query()->create([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'password' => Hash::make('password123'),
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chișinău',
            'status' => Station::STATUS_CHARGING,
            'qr_code' => 'station:volta-1',
            'ocpp_identity' => 'volta-1',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    2 => ['connectorId' => 2, 'status' => 'Charging'],
                ],
            ],
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'start_time' => now()->subMinutes(2),
            'kwh_consumed' => 0,
            'ocpp_transaction_id' => null,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/stop', [
                'station_id' => $station->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'stopping');

        $this->assertDatabaseHas('ocpp_commands', [
            'station_id' => $station->id,
            'charging_session_id' => $session->id,
            'action' => 'RemoteStopTransaction',
        ]);

        $command = OcppCommand::query()->first();
        $this->assertSame($session->id, $command->payload['transactionId']);
    }
}
