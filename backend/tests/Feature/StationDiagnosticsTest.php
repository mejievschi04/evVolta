<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Services\OcppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StationDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backoffice_can_request_station_diagnostics(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Backoffice Admin',
            'email' => 'admin@example.test',
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        $mock = Mockery::mock(OcppService::class);
        $mock->shouldReceive('requestDiagnostics')
            ->once()
            ->with(Mockery::on(fn (Station $item) => $item->id === $station->id))
            ->andReturn([
                'status' => 'queued',
                'command_id' => 42,
                'location' => 'ftp://diagnostics.local/evolta/',
            ]);

        $this->app->instance(OcppService::class, $mock);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])->postJson("/backoffice/stations/{$station->id}/diagnostics")
            ->assertOk()
            ->assertJsonPath('data.command_id', 42);
    }
}
