<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use App\Services\OcppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SessionLiveStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_endpoint_returns_session_and_live_status(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    2 => ['connectorId' => 2, 'status' => 'Charging'],
                ],
            ],
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'start_time' => now()->subMinutes(5),
            'kwh_consumed' => 1.2,
            'live_metrics' => ['power_kw' => 7.4],
        ]);

        $response = $this->actingAs($user, 'api')
            ->getJson("/api/sessions/{$session->id}/live");

        $response->assertOk()
            ->assertJsonPath('session.id', $session->id)
            ->assertJsonPath('live_status.connector_status', 'Charging')
            ->assertJsonStructure(['stream_version']);
    }

    public function test_live_endpoint_forbids_other_users(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $owner->id,
            'station_id' => $station->id,
            'start_time' => now(),
        ]);

        $this->actingAs($other, 'api')
            ->getJson("/api/sessions/{$session->id}/live")
            ->assertForbidden();
    }
}
