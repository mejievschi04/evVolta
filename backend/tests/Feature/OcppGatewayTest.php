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

class OcppGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_mode_queues_remote_start_command_for_connected_station(): void
    {
        Config::set('services.ocpp.mode', 'gateway');

        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'Volta Test 01',
            'location' => 'Depot',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:volta-test-01',
            'ocpp_identity' => 'volta-test-01',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);
        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => now(),
            'ocpp_id_tag' => OcppService::idTagForUser($user),
            'ocpp_connector_id' => 2,
        ]);

        $response = app(OcppService::class)->startTransaction($station, $session, $user);

        $this->assertSame('gateway', $response['mode']);
        $this->assertSame('queued', $response['status']);
        $this->assertDatabaseHas('ocpp_commands', [
            'station_id' => $station->id,
            'charging_session_id' => $session->id,
            'action' => 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_PENDING,
        ]);

        $command = OcppCommand::query()->first();
        $this->assertSame(2, $command->payload['connectorId']);
        $this->assertSame(OcppService::idTagForUser($user), $command->payload['idTag']);
    }
}
