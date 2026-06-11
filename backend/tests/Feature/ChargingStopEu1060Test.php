<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\Station;
use App\Models\User;
use App\Services\OcppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChargingStopEu1060Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ocpp.mode' => 'gateway']);
    }

    public function test_remote_stop_uses_cs_assigned_transaction_id_not_meter_values_zero(): void
    {
        $user = User::query()->create([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'password' => Hash::make('password123'),
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
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
            'ocpp_transaction_id' => '16',
            'start_time' => now()->subMinutes(10),
            'kwh_consumed' => 2.6,
            'live_metrics' => [
                'reported_transaction_id' => 0,
                'power_kw' => 3.5,
            ],
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/stop', ['station_id' => $station->id])
            ->assertOk()
            ->assertJsonPath('status', 'stopping');

        $command = OcppCommand::query()->first();
        $this->assertSame(16, $command->payload['transactionId']);
        $this->assertSame(16, $session->fresh()->remoteStopTransactionId());
    }

    public function test_rejected_stop_does_not_close_session_in_api(): void
    {
        $user = User::query()->create([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'password' => Hash::make('password123'),
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_transaction_id' => '16',
            'start_time' => now()->subMinutes(5),
            'kwh_consumed' => 1.2,
            'live_metrics' => ['reported_transaction_id' => 0],
        ]);

        OcppCommand::query()->create([
            'station_id' => $station->id,
            'charging_session_id' => $session->id,
            'message_uid' => 'rejected-stop',
            'action' => 'RemoteStopTransaction',
            'status' => OcppCommand::STATUS_REJECTED,
            'payload' => ['transactionId' => 16],
            'response_payload' => ['status' => 'Rejected'],
            'available_at' => now(),
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/stop', ['station_id' => $station->id])
            ->assertOk()
            ->assertJsonPath('status', 'stopping');

        $this->assertDatabaseHas('charging_sessions', [
            'id' => $session->id,
            'end_time' => null,
        ]);
    }

    public function test_stop_service_builds_assigned_transaction_payload(): void
    {
        $user = User::query()->create([
            'name' => 'Driver One',
            'email' => 'driver2@example.test',
            'password' => Hash::make('password123'),
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_transaction_id' => '42',
            'start_time' => now()->subMinute(),
            'live_metrics' => ['reported_transaction_id' => 0],
        ]);

        app(OcppService::class)->stopTransaction($station, $session);

        $this->assertDatabaseHas('ocpp_commands', [
            'station_id' => $station->id,
            'action' => 'RemoteStopTransaction',
            'payload->transactionId' => 42,
        ]);
    }
}
