<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use App\Services\OcppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StationUnlockConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlock_connector_requires_active_session(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user, 'api')
            ->postJson("/api/stations/{$station->id}/unlock-connector")
            ->assertNotFound();
    }

    public function test_unlock_connector_queues_ocpp_command(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'start_time' => now(),
        ]);

        $mock = Mockery::mock(OcppService::class);
        $mock->shouldReceive('unlockConnector')
            ->once()
            ->with(
                Mockery::on(fn (Station $item) => $item->id === $station->id),
                2
            )
            ->andReturn(['status' => 'queued', 'command_id' => 99]);

        $this->app->instance(OcppService::class, $mock);

        $this->actingAs($user, 'api')
            ->postJson("/api/stations/{$station->id}/unlock-connector", ['connector_id' => 2])
            ->assertOk()
            ->assertJsonPath('command_id', 99);
    }
}
