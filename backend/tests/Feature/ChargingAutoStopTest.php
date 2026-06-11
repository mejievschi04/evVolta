<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use App\Services\ChargingStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChargingAutoStopTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_session_finalizes_when_connector_becomes_available_after_charging(): void
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

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_transaction_id' => '15',
            'start_source' => 'app',
            'start_time' => now()->subMinutes(12),
            'kwh_consumed' => 0,
            'live_metrics' => [
                'power_kw' => 2.24,
                'energy_integrated_kwh' => 0.45,
            ],
        ]);

        $station->update([
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                    2 => ['connectorId' => 2, 'status' => 'Available'],
                ],
            ],
        ]);

        $service = app(ChargingStopService::class);
        $this->assertTrue($service->shouldAutoFinalizeOnConnectorRelease($session, $station->fresh(), 'Available'));

        $closed = $service->maybeAutoFinalizeOnStationStatus($station->fresh(), 2, 'Available');
        $this->assertNotNull($closed);
        $this->assertNotNull($closed->end_time);
        $this->assertGreaterThan(0, (float) $closed->kwh_consumed);
    }

    public function test_stale_finishing_before_charge_does_not_auto_finalize(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_configuration' => [
                'connectors' => [
                    2 => ['connectorId' => 2, 'status' => 'Finishing'],
                ],
            ],
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'start_source' => 'app',
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        $service = app(ChargingStopService::class);
        $this->assertFalse($service->shouldAutoFinalizeOnConnectorRelease($session, $station, 'Finishing'));
    }
}
