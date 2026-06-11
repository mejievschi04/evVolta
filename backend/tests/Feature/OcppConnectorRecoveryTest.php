<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\Station;
use App\Models\User;
use App\Services\OcppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class OcppConnectorRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.ocpp.mode', 'gateway');
    }

    public function test_recover_connector_queues_availability_cycle_reset_and_remote_start(): void
    {
        $user = $this->createPersonalUser();
        $station = Station::query()->create([
            'name' => 'Recovery station',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'recovery-station',
            'ocpp_identity' => 'recovery-station',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    2 => ['connectorId' => 2, 'status' => 'SuspendedEV'],
                ],
                'local_id_tags' => ['A5CD0CBD'],
            ],
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_id_tag' => 'A5CD0CBD',
            'start_time' => now()->subSeconds(20),
            'kwh_consumed' => 0,
        ]);

        $commandIds = app(OcppService::class)->recoverConnectorForRemoteStart($station, 2, $session);

        $this->assertCount(4, $commandIds);
        $this->assertDatabaseHas('ocpp_commands', [
            'station_id' => $station->id,
            'action' => 'ChangeAvailability',
        ]);
        $this->assertDatabaseHas('ocpp_commands', [
            'station_id' => $station->id,
            'action' => 'Reset',
        ]);
        $this->assertDatabaseHas('ocpp_commands', [
            'station_id' => $station->id,
            'charging_session_id' => $session->id,
            'action' => 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_PENDING,
        ]);
    }

    public function test_recover_connector_is_rate_limited(): void
    {
        $station = Station::query()->create([
            'name' => 'Recovery station 2',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'recovery-station-2',
            'ocpp_identity' => 'recovery-station-2',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
        ]);

        OcppCommand::query()->create([
            'station_id' => $station->id,
            'message_uid' => (string) \Illuminate\Support\Str::uuid(),
            'action' => 'ChangeAvailability',
            'status' => OcppCommand::STATUS_ACCEPTED,
            'payload' => ['connectorId' => 2, 'type' => 'Inoperative'],
            'acknowledged_at' => now(),
        ]);

        $commandIds = app(OcppService::class)->recoverConnectorForRemoteStart($station, 2, null);

        $this->assertSame([], $commandIds);
    }

    public function test_force_recovery_bypasses_cooldown(): void
    {
        $station = Station::query()->create([
            'name' => 'Recovery station 3',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'recovery-station-3',
            'ocpp_identity' => 'recovery-station-3',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
        ]);

        OcppCommand::query()->create([
            'station_id' => $station->id,
            'message_uid' => (string) \Illuminate\Support\Str::uuid(),
            'action' => 'Reset',
            'status' => OcppCommand::STATUS_ACCEPTED,
            'payload' => ['type' => 'Soft'],
            'acknowledged_at' => now(),
        ]);

        $commandIds = app(OcppService::class)->recoverConnectorForRemoteStart($station, 2, null, 'remote_start_rejected', true);

        $this->assertNotEmpty($commandIds);
    }
}
