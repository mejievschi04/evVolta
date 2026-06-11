<?php

namespace Tests\Feature;

use App\Models\OcppCommand;
use App\Models\Station;
use App\Services\OcppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class OcppTriggerMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_connector_state_queues_trigger_message_for_dual_charger(): void
    {
        Config::set('services.ocpp.mode', 'gateway');

        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                    2 => ['connectorId' => 2, 'status' => 'Available'],
                ],
            ],
        ]);

        app(OcppService::class)->syncConnectorStateBeforeStart($station);

        $this->assertDatabaseHas('ocpp_commands', [
            'station_id' => $station->id,
            'action' => 'TriggerMessage',
            'status' => OcppCommand::STATUS_PENDING,
        ]);

        $this->assertGreaterThanOrEqual(
            2,
            OcppCommand::query()->where('station_id', $station->id)->where('action', 'TriggerMessage')->count()
        );
    }
}
