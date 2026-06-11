<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\OcppMessage;
use App\Models\Station;
use App\Models\User;
use App\Services\OcppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChargingStartGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_resolves_preparing_connector_and_queues_remote_start(): void
    {
        Config::set('services.ocpp.mode', 'gateway');

        $user = $this->createPersonalUser([
            'name' => 'Driver',
            'email' => 'driver@example.test',
        ]);

        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => '419400481F59D7',
            'ocpp_identity' => '419400481F59D7',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                    2 => ['connectorId' => 2, 'status' => 'Preparing'],
                ],
            ],
        ]);

        OcppMessage::query()->create([
            'station_id' => $station->id,
            'direction' => 'inbound',
            'action' => 'StartTransaction',
            'status' => 'received',
            'payload' => ['connectorId' => 2, 'idTag' => 'A5CD0CBD'],
            'received_at' => now(),
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
            ])
            ->assertCreated()
            ->assertJsonPath('connector_id', 2);

        $this->assertDatabaseHas('charging_sessions', [
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_id_tag' => 'A5CD0CBD',
        ]);

        $this->assertDatabaseHas('ocpp_commands', [
            'station_id' => $station->id,
            'action' => 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_PENDING,
        ]);

        $command = OcppCommand::query()
            ->where('action', 'RemoteStartTransaction')
            ->latest('id')
            ->first();
        $this->assertSame(2, $command->payload['connectorId']);
        $this->assertSame('A5CD0CBD', $command->payload['idTag']);
    }

    public function test_start_requeues_remote_start_for_existing_session_without_transaction(): void
    {
        Config::set('services.ocpp.mode', 'gateway');

        $user = $this->createPersonalUser([
            'name' => 'Driver',
            'email' => 'driver@example.test',
        ]);

        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
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
            'ocpp_connector_id' => 1,
            'ocpp_id_tag' => 'user:' . $user->id,
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        OcppCommand::query()->create([
            'station_id' => $station->id,
            'charging_session_id' => $session->id,
            'message_uid' => 'old-command',
            'action' => 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_REJECTED,
            'payload' => ['connectorId' => 1, 'idTag' => 'user:' . $user->id],
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
            ])
            ->assertCreated()
            ->assertJsonPath('connector_id', 2);

        $this->assertDatabaseHas('charging_sessions', [
            'id' => $session->id,
            'ocpp_connector_id' => 2,
            'ocpp_id_tag' => OcppService::idTagForUser($user),
        ]);

        $this->assertDatabaseHas('ocpp_commands', [
            'charging_session_id' => $session->id,
            'action' => 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_PENDING,
        ]);
    }

    public function test_start_rejects_when_ocpp_is_disconnected(): void
    {
        Config::set('services.ocpp.mode', 'gateway');

        $user = $this->createPersonalUser([
            'name' => 'Driver',
            'email' => 'driver-disconnected@example.test',
        ]);

        $station = Station::query()->create([
            'name' => 'Offline charger',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'offline-station',
            'ocpp_identity' => 'offline-station',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_DISCONNECTED,
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                ],
            ],
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
                'connector_id' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Statia nu este conectata la gateway-ul OCPP.');
    }
}
