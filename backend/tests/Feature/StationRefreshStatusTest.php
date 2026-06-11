<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use App\Services\OcppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StationRefreshStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_status_returns_updated_live_status(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                    2 => ['connectorId' => 2, 'status' => 'Available'],
                ],
            ],
        ]);

        $refreshed = $station->fresh();
        $refreshed->update([
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                    2 => ['connectorId' => 2, 'status' => 'Preparing'],
                ],
            ],
        ]);

        $mock = Mockery::mock(OcppService::class);
        $mock->shouldReceive('refreshConnectorStatus')
            ->once()
            ->with(Mockery::on(fn (Station $item) => $item->id === $station->id))
            ->andReturn($refreshed);

        $this->app->instance(OcppService::class, $mock);

        $response = $this->actingAs($user, 'api')
            ->postJson("/api/stations/{$station->id}/refresh-status");

        $response->assertOk()
            ->assertJsonPath('station_id', $station->id)
            ->assertJsonPath('live_status.connected_connector_id', 2)
            ->assertJsonPath('live_status.connected_connector_label', 'B');
    }
}
