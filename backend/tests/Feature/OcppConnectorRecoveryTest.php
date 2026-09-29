<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\Station;
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

        $inoperative = OcppCommand::query()->findOrFail($commandIds[0]);
        $operative = OcppCommand::query()->findOrFail($commandIds[1]);
        $reset = OcppCommand::query()->findOrFail($commandIds[2]);
        $remoteStart = OcppCommand::query()->findOrFail($commandIds[3]);

        $this->assertSame('ChangeAvailability', $inoperative->action);
        $this->assertSame('Inoperative', $inoperative->payload['type']);
        $this->assertNull($inoperative->depends_on_command_id);

        $this->assertSame('ChangeAvailability', $operative->action);
        $this->assertSame('Operative', $operative->payload['type']);
        $this->assertSame($inoperative->id, $operative->depends_on_command_id);

        $this->assertSame('Reset', $reset->action);
        $this->assertSame($operative->id, $reset->depends_on_command_id);

        $this->assertSame('RemoteStartTransaction', $remoteStart->action);
        $this->assertSame($reset->id, $remoteStart->depends_on_command_id);
        $this->assertTrue($remoteStart->available_at->greaterThan(now()->addMonths(6)));

        $readyIds = OcppCommand::query()
            ->where('station_id', $station->id)
            ->readyToSend()
            ->pluck('id')
            ->all();

        $this->assertSame([$inoperative->id], $readyIds);
    }

    public function test_operative_ready_only_after_inoperative_accepted(): void
    {
        $station = Station::query()->create([
            'name' => 'Chain station',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'chain-station',
            'ocpp_identity' => 'chain-station',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
        ]);

        Config::set('services.ocpp.soft_reset_on_start_reject', false);

        $ids = app(OcppService::class)->recoverConnectorForRemoteStart($station, 1, null, 'stuck', true);
        $this->assertCount(2, $ids);

        $inoperative = OcppCommand::query()->findOrFail($ids[0]);
        $operative = OcppCommand::query()->findOrFail($ids[1]);

        $this->assertFalse(
            OcppCommand::query()->readyToSend()->whereKey($operative->id)->exists()
        );

        $inoperative->update([
            'status' => OcppCommand::STATUS_ACCEPTED,
            'acknowledged_at' => now(),
        ]);

        $this->assertTrue(
            OcppCommand::query()->readyToSend()->whereKey($operative->id)->exists()
        );
    }

    public function test_recover_skips_when_leakage_fault_present(): void
    {
        $station = Station::query()->create([
            'name' => 'Fault station',
            'location' => 'Test',
            'status' => Station::STATUS_OFFLINE,
            'qr_code' => 'fault-station',
            'ocpp_identity' => 'fault-station',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => [
                        'connectorId' => 1,
                        'status' => 'Faulted',
                        'errorCode' => 'LeakageRcmuError',
                    ],
                ],
            ],
        ]);

        $commandIds = app(OcppService::class)->recoverConnectorForRemoteStart($station, 1, null, 'stuck', true);

        $this->assertSame([], $commandIds);
        $this->assertTrue(app(OcppService::class)->connectorHasBlockingFault($station, 1));
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

    public function test_in_flight_commands_block_second_recovery(): void
    {
        $station = Station::query()->create([
            'name' => 'Busy station',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'busy-station',
            'ocpp_identity' => 'busy-station',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
        ]);

        $first = app(OcppService::class)->recoverConnectorForRemoteStart($station, 2, null, 'manual', true);
        $this->assertNotEmpty($first);

        $second = app(OcppService::class)->recoverConnectorForRemoteStart($station, 2, null, 'manual', true);
        $this->assertSame([], $second);
    }
}
