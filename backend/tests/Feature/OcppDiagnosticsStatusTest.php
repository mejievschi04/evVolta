<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use App\Models\OcppCommand;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class OcppDiagnosticsStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostics_status_notification_updates_latest_get_diagnostics_command(): void
    {
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        $command = OcppCommand::query()->create([
            'station_id' => $station->id,
            'message_uid' => 'diag-uid-1',
            'action' => 'GetDiagnostics',
            'status' => OcppCommand::STATUS_ACCEPTED,
            'payload' => [
                'location' => 'ftp://diagnostics.local/evolta/',
            ],
            'response_payload' => [
                'fileName' => 'diag-2026-06-10.log',
            ],
            'acknowledged_at' => now(),
        ]);

        $serve = app(OcppServe::class);
        $method = (new ReflectionClass($serve))->getMethod('onDiagnosticsStatusNotification');
        $method->setAccessible(true);
        $method->invoke($serve, $station, ['status' => 'Uploaded']);

        $command->refresh();

        $this->assertSame('Uploaded', $command->response_payload['upload_status']);
        $this->assertNotEmpty($command->response_payload['upload_status_at']);
        $this->assertSame('diag-2026-06-10.log', $command->response_payload['fileName']);
    }
}
