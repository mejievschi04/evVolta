<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Services\OcppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class ChargingResumeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_resume_suspended_ev_session(): void
    {
        Config::set('services.ocpp.mode', 'simulator');
        Config::set('billing.prepaid_wallet_enabled', false);

        $user = $this->createPersonalUser(['email' => 'resume-suspended@example.test']);

        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => 'resume-station-01',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'NumberOfConnectors' => 2,
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'SuspendedEV'],
                    2 => ['connectorId' => 2, 'status' => 'Available'],
                ],
            ],
        ]);

        $oldSession = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'ocpp_id_tag' => 'VOLTA00000001',
            'ocpp_transaction_id' => '77',
            'start_source' => 'app',
            'start_time' => now()->subMinutes(12),
            'kwh_consumed' => 1.5,
        ]);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/charging/resume', [
                'station_id' => $station->id,
                'session_id' => $oldSession->id,
                'connector_id' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('connector_id', 1)
            ->assertJsonPath('previous_session_id', $oldSession->id);

        $newSessionId = (int) $response->json('session.id');
        $this->assertNotSame((int) $oldSession->id, $newSessionId);
        $this->assertNotNull($oldSession->fresh()->end_time);
        $this->assertSame('UserResume', $oldSession->fresh()->ocpp_stop_reason);
        $this->assertDatabaseHas('charging_sessions', [
            'id' => $newSessionId,
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'start_source' => 'app_resume',
            'end_time' => null,
        ]);
    }

    public function test_resume_rejects_when_connector_not_paused(): void
    {
        Config::set('services.ocpp.mode', 'simulator');
        Config::set('billing.prepaid_wallet_enabled', false);

        $user = $this->createPersonalUser(['email' => 'resume-charging@example.test']);

        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => 'resume-station-02',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'NumberOfConnectors' => 2,
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Charging'],
                    2 => ['connectorId' => 2, 'status' => 'Available'],
                ],
            ],
        ]);

        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'ocpp_transaction_id' => '88',
            'start_time' => now()->subMinutes(5),
            'kwh_consumed' => 0.4,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/resume', [
                'station_id' => $station->id,
                'connector_id' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Portul nu este in pauza (SuspendedEV). Status actual: Charging.']);
    }

    public function test_resume_after_stop_on_suspended_port_without_open_session(): void
    {
        Config::set('services.ocpp.mode', 'gateway');
        Config::set('billing.prepaid_wallet_enabled', false);

        $user = $this->createPersonalUser(['email' => 'resume-after-stop@example.test']);

        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => 'resume-station-03',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'NumberOfConnectors' => 2,
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'SuspendedEV'],
                    2 => ['connectorId' => 2, 'status' => 'Available'],
                ],
            ],
        ]);

        $mock = Mockery::mock(OcppService::class)->makePartial();
        $mock->shouldReceive('isSimulatorMode')->andReturn(false);
        $mock->shouldReceive('ensureReadyForRemoteCommands')->andReturnNull();
        $mock->shouldReceive('remoteStartIdTag')->andReturn('VOLTA00000099');
        $mock->shouldReceive('recoverConnectorForRemoteStart')
            ->once()
            ->andReturn([101, 102, 103]);
        $this->app->instance(OcppService::class, $mock);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/resume', [
                'station_id' => $station->id,
                'connector_id' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('connector_id', 1)
            ->assertJsonPath('previous_session_id', null)
            ->assertJsonPath('ocpp.resume_recovery', true);

        $this->assertDatabaseHas('charging_sessions', [
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'start_source' => 'app_resume',
            'end_time' => null,
        ]);
    }

    public function test_session_live_exposes_can_resume_on_suspended_ev(): void
    {
        Config::set('services.ocpp.mode', 'simulator');

        $user = $this->createPersonalUser(['email' => 'resume-live@example.test']);

        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'SuspendedEV'],
                ],
            ],
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'ocpp_transaction_id' => '91',
            'start_time' => now()->subMinutes(3),
            'kwh_consumed' => 0.2,
        ]);

        $this->actingAs($user, 'api')
            ->getJson("/api/sessions/{$session->id}/live")
            ->assertOk()
            ->assertJsonPath('can_resume', true)
            ->assertJsonPath('connector_status', 'SuspendedEV');
    }
}
