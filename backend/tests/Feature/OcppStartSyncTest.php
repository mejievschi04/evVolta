<?php

namespace Tests\Feature;

use App\Models\OcppCommand;
use App\Models\Station;
use App\Services\OcppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class OcppStartSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_sync_forces_trigger_even_when_status_refresh_is_throttled(): void
    {
        Config::set('services.ocpp.mode', 'gateway');
        Config::set('services.ocpp.start_sync_wait_connected_ms', 0);

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
                    2 => ['connectorId' => 2, 'status' => 'Preparing'],
                ],
            ],
        ]);

        app(OcppService::class)->syncConnectorStateBeforeStart($station);
        app(OcppService::class)->syncConnectorStateBeforeStart($station->fresh());

        $this->assertGreaterThanOrEqual(
            2,
            OcppCommand::query()->where('station_id', $station->id)->where('action', 'TriggerMessage')->count()
        );
    }

    public function test_remote_start_is_queued_without_extra_delay(): void
    {
        Config::set('services.ocpp.mode', 'gateway');

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => 'volta-start-now',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
        ]);

        app(OcppService::class)->startTransaction($station);

        $command = OcppCommand::query()->where('action', 'RemoteStartTransaction')->first();

        $this->assertNotNull($command);
        $this->assertTrue($command->available_at->lessThanOrEqualTo(now()->addSecond()));
    }

    public function test_queue_meter_values_trigger_creates_trigger_message(): void
    {
        Config::set('services.ocpp.mode', 'gateway');

        $station = Station::query()->create([
            'name' => 'VOLTA meter',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => 'volta-meter',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
        ]);

        app(OcppService::class)->queueMeterValuesTrigger($station, 2);
        app(OcppService::class)->queueMeterValuesTrigger($station, 2, true);

        $this->assertDatabaseCount('ocpp_commands', 2);
        $this->assertDatabaseHas('ocpp_commands', [
            'station_id' => $station->id,
            'action' => 'TriggerMessage',
            'status' => OcppCommand::STATUS_PENDING,
        ]);

        $command = OcppCommand::query()->first();
        $this->assertSame('MeterValues', $command->payload['requestedMessage']);
        $this->assertSame(2, $command->payload['connectorId']);
    }
}
