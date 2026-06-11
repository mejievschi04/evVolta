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
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_DISCONNECTED,
            'last_ocpp_message_at' => now()->subSeconds(15),
        ]);

        $this->assertTrue($station->appearsConnectedToGateway());
        $this->assertSame('connected', $station->liveStatus()['connection_status']);
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
