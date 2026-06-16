<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class OcppDeferredHandshakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_allows_deferred_identity_handshake_for_base_ocpp_path(): void
    {
        $command = app(OcppServe::class);
        $method = (new ReflectionClass($command))->getMethod('allowsDeferredIdentityHandshake');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($command, 'ocpp'));
        $this->assertTrue($method->invoke($command, ''));
        $this->assertFalse($method->invoke($command, 'ocpp/serial-123'));
    }

    public function test_resolve_station_from_boot_payload_matches_serial(): void
    {
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => '5D419400481F59D750010067',
            'qr_code' => '5D419400481F59D750010067',
        ]);

        Station::query()->create([
            'name' => 'Vitra 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => 'vitra-st1',
            'qr_code' => 'station:vitra-1',
        ]);

        $command = app(OcppServe::class);
        $method = (new ReflectionClass($command))->getMethod('resolveStationFromBootPayload');
        $method->setAccessible(true);

        $resolved = $method->invoke($command, [
            'chargePointSerialNumber' => '5D419400481F59D750010067',
            'firmwareVersion' => 'ACM4_EVSE_V12.27',
        ]);

        $this->assertNotNull($resolved);
        $this->assertSame($station->id, $resolved->id);
    }
}
