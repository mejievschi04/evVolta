<?php

namespace Tests\Unit;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use App\Services\SessionEnergyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionEnergyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_integrates_power_when_meter_register_is_stuck(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'start_source' => 'app',
            'start_time' => Carbon::parse('2026-06-09T12:00:00Z'),
            'meter_start_kwh' => 0.15,
            'kwh_consumed' => 0,
            'live_metrics' => [
                'power_kw' => 2.0,
                'sampled_at' => '2026-06-09T12:10:00Z',
                'energy_integrated_kwh' => 0.33,
            ],
        ]);

        $service = new SessionEnergyService();
        $result = $service->resolveDeliveredKwh($session, 0.15, [
            'power_kw' => 2.24,
            'current_a' => 9.7,
            'sampled_at' => '2026-06-09T12:12:03Z',
        ]);

        $this->assertGreaterThan(0.4, $result['kwh_consumed']);
        $this->assertGreaterThan(0.4, $result['energy_integrated_kwh']);
    }

    public function test_telemetry_prefers_integrated_over_stuck_meter_delta(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'start_source' => 'app',
            'start_time' => now()->subMinutes(12),
            'meter_start_kwh' => 0.15,
            'kwh_consumed' => 0,
            'live_metrics' => [
                'energy_kwh' => 0.15,
                'energy_integrated_kwh' => 0.45,
                'power_kw' => 2.24,
            ],
        ]);

        $service = new SessionEnergyService();

        $this->assertSame(0.45, $service->telemetryKwhDelivered($session));
        $this->assertSame(0.45, $session->fresh()->telemetry['kwh_consumed']);
    }

    public function test_moving_meter_register_prefers_delta_over_power_integration(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'start_source' => 'app',
            'start_time' => now()->subMinutes(8),
            'meter_start_kwh' => 0.31,
            'kwh_consumed' => 0.3,
            'live_metrics' => [
                'energy_kwh' => 0.31,
                'energy_integrated_kwh' => 0.48,
                'power_kw' => 3.52,
                'sampled_at' => now()->subSeconds(5)->toIso8601String(),
            ],
        ]);

        $service = new SessionEnergyService();
        $result = $service->resolveDeliveredKwh($session, 0.357, [
            'power_kw' => 3.528,
            'current_a' => 14.986,
            'sampled_at' => now()->toIso8601String(),
        ]);

        $this->assertSame(0.047, $result['kwh_consumed']);

        $session->update([
            'kwh_consumed' => $result['kwh_consumed'],
            'live_metrics' => array_merge($session->live_metrics ?? [], [
                'previous_energy_kwh' => 0.31,
                'energy_kwh' => 0.357,
                'energy_integrated_kwh' => $result['energy_integrated_kwh'],
            ]),
        ]);

        $this->assertSame(0.047, $service->telemetryKwhDelivered($session->fresh()));
    }

    public function test_zero_meter_start_is_treated_as_unknown_baseline(): void
    {
        $this->assertNull(SessionEnergyService::effectiveMeterStart(0.0));
        $this->assertNull(SessionEnergyService::effectiveMeterStart(0.0004));
        $this->assertSame(0.068, SessionEnergyService::effectiveMeterStart(0.068));

        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'start_source' => 'app',
            'start_time' => now()->subMinutes(2),
            'meter_start_kwh' => 0.0,
            'kwh_consumed' => 0,
            'live_metrics' => [
                'energy_kwh' => 0.068,
                'power_kw' => 3.52,
                'sampled_at' => now()->subSeconds(5)->toIso8601String(),
            ],
        ]);

        $service = new SessionEnergyService();
        $result = $service->resolveDeliveredKwh($session, 0.101, [
            'power_kw' => 3.52,
            'current_a' => 14.9,
            'sampled_at' => now()->toIso8601String(),
        ]);

        $this->assertSame(0.101, $result['kwh_consumed']);
        $this->assertArrayNotHasKey('meter_start_kwh', $result);

        $session->update([
            'kwh_consumed' => $result['kwh_consumed'],
            'live_metrics' => ['energy_kwh' => 0.101, 'power_kw' => 3.52],
        ]);

        $this->assertSame(0.101, $service->telemetryKwhDelivered($session->fresh()));
    }

    public function test_session_relative_register_tracks_eu1060_wh_samples(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'start_source' => 'app',
            'start_time' => now()->subMinutes(1),
            'kwh_consumed' => 0,
        ]);

        $service = new SessionEnergyService();

        foreach ([0.035, 0.068, 0.101] as $registerKwh) {
            $result = $service->resolveDeliveredKwh($session->fresh(), $registerKwh, [
                'power_kw' => 3.52,
                'current_a' => 14.9,
                'sampled_at' => now()->toIso8601String(),
            ]);

            $this->assertSame($registerKwh, $result['kwh_consumed']);

            $session->update([
                'kwh_consumed' => $result['kwh_consumed'],
                'live_metrics' => [
                    'energy_kwh' => $registerKwh,
                    'power_kw' => 3.52,
                ],
            ]);
        }
    }
}
