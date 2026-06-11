<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Services\OcppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StationGatewayConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_station_appears_connected_when_recent_ocpp_traffic_exists(): void
    {
        Config::set('services.ocpp.mode', 'gateway');

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_DISCONNECTED,
            'last_ocpp_message_at' => now()->subSeconds(15),
        ]);

        $this->assertTrue($station->appearsConnectedToGateway());
        $this->assertSame('disconnected', $station->liveStatus()['connection_status']);
        $this->assertSame(Station::STATUS_OFFLINE, $station->liveStatus()['availability']);
        $this->assertSame(Station::STATUS_OFFLINE, $station->displayStatus());
    }

    public function test_station_is_offline_in_gateway_mode_when_ocpp_disconnected(): void
    {
        Config::set('services.ocpp.mode', 'gateway');

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_DISCONNECTED,
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                    2 => ['connectorId' => 2, 'status' => 'Available'],
                ],
            ],
        ]);

        $live = $station->liveStatus();

        $this->assertFalse($station->isOcppOnline());
        $this->assertSame(Station::STATUS_OFFLINE, $live['availability']);
        $this->assertFalse($live['can_start']);
        $this->assertSame(Station::STATUS_OFFLINE, $station->displayStatus());
        $this->assertSame('Offline', $live['connectors'][0]['status']);
        $this->assertSame(Station::STATUS_OFFLINE, $live['connectors'][0]['availability']);
    }

    public function test_stale_connected_db_status_is_marked_offline(): void
    {
        Config::set('services.ocpp.mode', 'gateway');

        $station = Station::query()->create([
            'name' => 'VOLTA stale',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => 'stale-station-id',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);

        $this->assertFalse($station->appearsConnectedToGateway());
        $this->assertFalse($station->isOcppOnline());

        $updated = Station::markStaleOcppConnectionsOffline();

        $this->assertSame(1, $updated);
        $station->refresh();
        $this->assertSame(Station::OCPP_CONNECTION_DISCONNECTED, $station->ocpp_connection_status);
        $this->assertSame(Station::STATUS_OFFLINE, $station->status);
    }

    public function test_stop_queues_remote_command_when_db_status_is_stale_but_station_is_live(): void
    {
        Config::set('services.ocpp.mode', 'gateway');

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_DISCONNECTED,
            'last_ocpp_message_at' => now()->subSeconds(10),
        ]);

        $response = app(OcppService::class)->stopTransaction($station);

        $this->assertSame('queued', $response['status']);
        $this->assertDatabaseHas('ocpp_commands', [
            'station_id' => $station->id,
            'action' => 'RemoteStopTransaction',
        ]);
    }
}
