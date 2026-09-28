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
        $reflection = new ReflectionClass($command);
        if (! $reflection->hasMethod('allowsDeferredIdentityHandshake')) {
            $this->markTestSkipped('OcppServe::allowsDeferredIdentityHandshake was removed/refactored.');
        }

        $method = $reflection->getMethod('allowsDeferredIdentityHandshake');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($command, 'ocpp'));
        $this->assertTrue($method->invoke($command, ''));
        $this->assertFalse($method->invoke($command, 'ocpp/serial-123'));
    }

    public function test_resolve_station_from_boot_payload_matches_serial(): void
    {
        $command = app(OcppServe::class);
        $reflection = new ReflectionClass($command);
        if (! $reflection->hasMethod('resolveStationFromBootPayload')) {
            $this->markTestSkipped('OcppServe::resolveStationFromBootPayload was removed/refactored.');
        }

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
            'qr_code' => 'vitra-st1',
        ]);

        $method = $reflection->getMethod('resolveStationFromBootPayload');
        $method->setAccessible(true);
        $resolved = $method->invoke($command, [
            'chargePointSerialNumber' => '5D419400481F59D750010067',
        ]);

        $this->assertNotNull($resolved);
        $this->assertSame($station->id, $resolved->id);
    }
}
