<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DualPortChargingTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_user_can_start_on_free_port_while_first_charges(): void
    {
        Config::set('services.ocpp.mode', 'simulator');
        Config::set('billing.prepaid_wallet_enabled', false);

        $userA = $this->createPersonalUser(['email' => 'driver-a@example.test']);
        $userB = $this->createPersonalUser(['email' => 'driver-b@example.test']);

        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => 'dual-charger-01',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'NumberOfConnectors' => 2,
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Charging'],
                    2 => ['connectorId' => 2, 'status' => 'Preparing'],
                ],
            ],
        ]);

        ChargingSession::query()->create([
            'user_id' => $userA->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'ocpp_id_tag' => 'VOLTA00000001',
            'ocpp_transaction_id' => '101',
            'start_source' => 'app',
            'start_time' => now()->subMinutes(10),
            'kwh_consumed' => 2.5,
        ]);

        $this->actingAs($userB, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
                'connector_id' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('connector_id', 2);

        $this->assertDatabaseHas('charging_sessions', [
            'user_id' => $userB->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'end_time' => null,
        ]);

        $this->assertDatabaseHas('charging_sessions', [
            'user_id' => $userA->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'end_time' => null,
        ]);
    }

    public function test_second_user_blocked_when_target_port_already_in_use(): void
    {
        Config::set('services.ocpp.mode', 'simulator');
        Config::set('billing.prepaid_wallet_enabled', false);

        $userA = $this->createPersonalUser(['email' => 'driver-a2@example.test']);
        $userB = $this->createPersonalUser(['email' => 'driver-b2@example.test']);

        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
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
            'user_id' => $userA->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'ocpp_transaction_id' => '201',
            'start_time' => now(),
            'kwh_consumed' => 1,
        ]);

        $this->actingAs($userB, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
                'connector_id' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Conectorul este deja folosit de alt utilizator.');
    }

    public function test_third_session_blocked_when_all_ports_busy(): void
    {
        Config::set('services.ocpp.mode', 'simulator');
        Config::set('billing.prepaid_wallet_enabled', false);

        $userA = $this->createPersonalUser(['email' => 'driver-a3@example.test']);
        $userB = $this->createPersonalUser(['email' => 'driver-b3@example.test']);
        $userC = $this->createPersonalUser(['email' => 'driver-c3@example.test']);

        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'NumberOfConnectors' => 2,
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                    2 => ['connectorId' => 2, 'status' => 'Available'],
                ],
            ],
        ]);

        foreach ([[$userA, 1], [$userB, 2]] as [$user, $connectorId]) {
            ChargingSession::query()->create([
                'user_id' => $user->id,
                'station_id' => $station->id,
                'ocpp_connector_id' => $connectorId,
                'ocpp_transaction_id' => (string) (300 + $connectorId),
                'start_time' => now(),
                'kwh_consumed' => 1,
            ]);
        }

        $this->actingAs($userC, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
            ])
            ->assertStatus(422);

        $this->assertSame(2, ChargingSession::query()
            ->where('station_id', $station->id)
            ->whereNull('end_time')
            ->count());
    }

    public function test_resolve_start_connector_skips_port_with_active_session(): void
    {
        $user = $this->createPersonalUser();

        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
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
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        $this->assertSame(2, $station->resolveStartConnectorId(2));
    }
}
