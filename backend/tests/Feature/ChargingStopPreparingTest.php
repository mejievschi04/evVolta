<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChargingStopPreparingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ocpp.mode' => 'gateway',
        ]);
    }

    public function test_stop_queues_remote_command_for_preparing_session_without_ocpp_transaction(): void
    {
        $user = User::query()->create([
            'name' => 'Driver One',
            'email' => 'driver@example.test',
            'password' => Hash::make('password123'),
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chișinău',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => '419400481F59D7',
            'ocpp_identity' => '419400481F59D7',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    2 => ['connectorId' => 2, 'status' => 'Preparing'],
                ],
            ],
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'start_time' => now()->subMinute(),
            'kwh_consumed' => 0,
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
            'status' => OcppCommand::STATUS_PENDING,
        ]);

        $command = OcppCommand::query()->first();
        $this->assertSame($session->id, $command->payload['transactionId']);
    }
}
