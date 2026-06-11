<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class OcppBootNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_boot_notification_preserves_connector_state_and_local_tags(): void
    {
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                    2 => ['connectorId' => 2, 'status' => 'Preparing'],
                ],
                'local_id_tags' => [
                    2 => 'A5CD0CBD',
                ],
                'live_meter' => ['power_kw' => 2.2],
            ],
        ]);

        $command = app(OcppServe::class);
        $method = (new ReflectionClass($command))->getMethod('onBootNotification');
        $method->setAccessible(true);
        $method->invoke($command, $station, [
            'chargePointVendor' => 'VENDOR',
            'chargePointModel' => 'EU1060_TYPE_II',
            'chargePointSerialNumber' => '5D419400481F59D750010067',
            'firmwareVersion' => 'ACM4_EVSE_V12.27',
        ]);

        $station->refresh();
        $configuration = $station->ocpp_configuration;

        $this->assertSame('EU1060_TYPE_II', $configuration['chargePointModel']);
        $this->assertSame('Preparing', $configuration['connectors'][2]['status']);
        $this->assertSame('A5CD0CBD', $configuration['local_id_tags'][2]);
        $this->assertSame(2.2, $configuration['live_meter']['power_kw']);
    }
}
