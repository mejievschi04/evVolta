<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use App\Services\OcppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StationResetConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_connector_requires_active_session(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        $this->actingAs($user, 'api')
            ->postJson("/api/stations/{$station->id}/reset-connector")
            ->assertNotFound()
            ->assertJsonPath('message', 'Nu exista o sesiune activa pe aceasta statie.');
    }

    public function test_reset_connector_queues_manual_recovery(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_id_tag' => 'A5CD0CBD',
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        $mock = Mockery::mock(OcppService::class);
        $mock->shouldReceive('manualResetConnector')
            ->once()
            ->with(
                Mockery::on(fn (Station $item) => $item->id === $station->id),
                2,
                Mockery::on(fn (ChargingSession $item) => $item->id === $session->id),
                true
            )
            ->andReturn([
                'status' => 'queued',
                'station_id' => $station->id,
                'connector_id' => 2,
                'command_ids' => [11, 12, 13, 14],
            ]);

        $this->app->instance(OcppService::class, $mock);

        $this->actingAs($user, 'api')
            ->postJson("/api/stations/{$station->id}/reset-connector", [
                'connector_id' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('connector_id', 2)
            ->assertJsonCount(4, 'command_ids');
    }
}
