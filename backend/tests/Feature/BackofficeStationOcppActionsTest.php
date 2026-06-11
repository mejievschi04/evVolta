<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use App\Services\ChargingStopService;
use App\Services\OcppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BackofficeStationOcppActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backoffice_can_refresh_station_status(): void
    {
        $admin = $this->createAdminUser();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        $mock = Mockery::mock(OcppService::class);
        $mock->shouldReceive('refreshConnectorStatus')
            ->once()
            ->with(
                Mockery::on(fn (Station $item) => $item->id === $station->id),
                0,
                true
            )
            ->andReturn($station);

        $this->app->instance(OcppService::class, $mock);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson("/backoffice/stations/{$station->id}/refresh-status")
            ->assertOk()
            ->assertJsonPath('message', 'Status OCPP actualizat.');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backoffice.station.refresh_status',
            'station_id' => $station->id,
        ]);
    }

    public function test_backoffice_can_unlock_station_connector(): void
    {
        $admin = $this->createAdminUser();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        $mock = Mockery::mock(OcppService::class);
        $mock->shouldReceive('unlockConnector')
            ->once()
            ->with(
                Mockery::on(fn (Station $item) => $item->id === $station->id),
                2
            )
            ->andReturn(['status' => 'queued', 'command_id' => 42]);

        $this->app->instance(OcppService::class, $mock);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson("/backoffice/stations/{$station->id}/unlock-connector", ['connector_id' => 2])
            ->assertOk()
            ->assertJsonPath('data.command_id', 42);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backoffice.station.unlock_connector',
            'station_id' => $station->id,
        ]);
    }

    public function test_backoffice_can_stop_active_station_session(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_transaction_id' => '31',
            'start_time' => now()->subMinutes(5),
            'kwh_consumed' => 0.12,
        ]);

        $mock = Mockery::mock(ChargingStopService::class);
        $mock->shouldReceive('requestStop')
            ->once()
            ->andReturn([
                'status' => 'stopping',
                'message' => 'Comanda de oprire a fost trimisa catre statie.',
                'session' => $session->fresh(),
                'station' => $station->fresh(),
            ]);

        $this->app->instance(ChargingStopService::class, $mock);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson("/backoffice/stations/{$station->id}/stop-active-session")
            ->assertOk()
            ->assertJsonPath('data.session_id', $session->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backoffice.session.stop_requested',
            'subject_id' => $session->id,
        ]);
    }

    public function test_backoffice_stop_active_session_returns_404_when_idle(): void
    {
        $admin = $this->createAdminUser();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->postJson("/backoffice/stations/{$station->id}/stop-active-session")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Statia nu are o sesiune activa.');
    }
}
