<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\Station;
use App\Models\User;
use App\Services\OcppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class OcppRemoteStartRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.ocpp.mode', 'gateway');
    }

    public function test_requeue_remote_start_after_rejected_command(): void
    {
        $user = $this->createPersonalUser();
        $station = Station::query()->create([
            'name' => 'Retry station',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'retry-station',
            'ocpp_identity' => 'retry-station',
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
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        OcppCommand::query()->create([
            'station_id' => $station->id,
            'charging_session_id' => $session->id,
            'message_uid' => (string) \Illuminate\Support\Str::uuid(),
            'action' => 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_REJECTED,
            'payload' => ['connectorId' => 2, 'idTag' => 'A5CD0CBD'],
            'acknowledged_at' => now(),
        ]);

        $command = app(OcppService::class)->requeueRemoteStartForSession($station, $session, 2);

        $this->assertNotNull($command);
        $this->assertSame(OcppCommand::STATUS_PENDING, $command->status);
        $this->assertSame(2, $command->payload['connectorId']);
        $this->assertSame('A5CD0CBD', $command->payload['idTag']);
    }

    public function test_requeue_skips_when_remote_start_already_in_flight(): void
    {
        $user = $this->createPersonalUser();
        $station = Station::query()->create([
            'name' => 'Retry station 2',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'retry-station-2',
            'ocpp_identity' => 'retry-station-2',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'start_time' => now(),
        ]);

        OcppCommand::query()->create([
            'station_id' => $station->id,
            'charging_session_id' => $session->id,
            'message_uid' => (string) \Illuminate\Support\Str::uuid(),
            'action' => 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_PENDING,
            'payload' => ['connectorId' => 2, 'idTag' => 'A5CD0CBD'],
        ]);

        $command = app(OcppService::class)->requeueRemoteStartForSession($station, $session);

        $this->assertNull($command);
    }

    public function test_activate_pending_session_skips_requeue_after_recent_accepted_remote_start(): void
    {
        $user = $this->createPersonalUser();
        $station = Station::query()->create([
            'name' => 'Retry station 3',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'retry-station-3',
            'ocpp_identity' => 'retry-station-3',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    2 => ['connectorId' => 2, 'status' => 'SuspendedEV'],
                ],
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

        OcppCommand::query()->create([
            'station_id' => $station->id,
            'charging_session_id' => $session->id,
            'message_uid' => (string) \Illuminate\Support\Str::uuid(),
            'action' => 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_ACCEPTED,
            'payload' => ['connectorId' => 2, 'idTag' => 'A5CD0CBD'],
            'acknowledged_at' => now()->subSeconds(5),
        ]);

        $beforeCount = OcppCommand::query()
            ->where('charging_session_id', $session->id)
            ->where('action', 'RemoteStartTransaction')
            ->count();

        $command = app(OcppServe::class);
        $method = (new \ReflectionClass($command))->getMethod('activatePendingSessionForConnector');
        $method->setAccessible(true);
        $method->invoke($command, $station, 2);

        $afterCount = OcppCommand::query()
            ->where('charging_session_id', $session->id)
            ->where('action', 'RemoteStartTransaction')
            ->count();

        $this->assertSame($beforeCount, $afterCount);
    }
}
