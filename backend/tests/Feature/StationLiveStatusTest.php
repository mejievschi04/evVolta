<?php

namespace Tests\Feature;

use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationLiveStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_preparing_connector_allows_remote_start(): void
    {
        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Charging'],
                    2 => ['connectorId' => 2, 'status' => 'Preparing'],
                ],
            ],
        ]);

        $this->assertFalse($station->canAcceptRemoteStart(1));
        $this->assertTrue($station->canAcceptRemoteStart(2));

        $live = $station->liveStatus(2);

        $this->assertSame('preparing', $live['availability']);
        $this->assertTrue($live['can_start']);
        $this->assertSame('Preparing', $live['connector_status']);
        $this->assertSame(2, $live['connected_connector_id']);
        $this->assertSame('B', $live['connected_connector_label']);
    }

    public function test_resolve_start_connector_id_prefers_preparing_port(): void
    {
        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                    2 => ['connectorId' => 2, 'status' => 'Preparing'],
                ],
            ],
        ]);

        $this->assertSame(2, $station->resolveStartConnectorId(null));
        $this->assertSame(2, $station->resolveStartConnectorId(1));
    }

    public function test_suspended_ev_connector_is_detected_as_connected(): void
    {
        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                    2 => ['connectorId' => 2, 'status' => 'SuspendedEV'],
                ],
            ],
        ]);

        $live = $station->liveStatus();

        $this->assertSame(2, $live['connected_connector_id']);
        $this->assertTrue($live['can_start']);
        $this->assertSame(2, $station->resolveStartConnectorId(null));
    }

    public function test_stale_finishing_on_dual_charger_is_detected_and_startable(): void
    {
        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                    2 => ['connectorId' => 2, 'status' => 'Finishing'],
                ],
            ],
        ]);

        $live = $station->liveStatus();

        $this->assertSame(2, $station->detectConnectedConnectorId());
        $this->assertSame(2, $live['connected_connector_id']);
        $this->assertTrue($live['can_start']);
        $this->assertTrue($station->canAcceptRemoteStart(2));
        $this->assertSame(2, $station->resolveStartConnectorId(null));
        $this->assertSame('preparing', $live['connectors'][1]['availability']);
        $this->assertTrue($live['connectors'][1]['is_stale_finishing']);
    }
}
