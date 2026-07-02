<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use App\Services\ChargingStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChargingSessionReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_closes_duplicate_open_sessions_on_same_connector(): void
    {
        $user = User::query()->create([
            'name' => 'Driver',
            'email' => 'reconcile@example.test',
            'password' => bcrypt('password123'),
        ]);

        $station = Station::query()->create([
            'name' => 'Dual charger',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_configuration' => [
                'NumberOfConnectors' => 2,
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                    2 => ['connectorId' => 2, 'status' => 'Available'],
                ],
            ],
        ]);

        $older = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'start_time' => now()->subMinutes(20),
            'kwh_consumed' => 4.464,
        ]);

        $newer = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_transaction_id' => '15',
            'start_time' => now()->subMinute(),
            'kwh_consumed' => 0.75,
        ]);

        $kept = app(ChargingStopService::class)->reconcileOpenSessionsBeforeStart($station, $user->id, 2);

        $older->refresh();
        $newer->refresh();

        $this->assertNotNull($kept);
        $this->assertSame($newer->id, $kept->id);
        $this->assertNotNull($older->end_time);
        $this->assertSame('StaleDuplicate', $older->ocpp_stop_reason);
        $this->assertNull($newer->end_time);
    }

    public function test_start_charging_reconciles_before_creating_another_row(): void
    {
        config(['services.ocpp.mode' => 'simulator']);
        config(['billing.prepaid_wallet_enabled' => false]);

        $user = $this->createPersonalUser(['email' => 'start-reconcile@example.test']);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_configuration' => [
                'NumberOfConnectors' => 2,
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Available'],
                    2 => ['connectorId' => 2, 'status' => 'Preparing'],
                ],
            ],
        ]);

        $zombie = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'start_time' => now()->subMinutes(15),
            'kwh_consumed' => 4.464,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', [
                'station_id' => $station->id,
                'connector_id' => 2,
            ])
            ->assertCreated();

        $zombie->refresh();

        $this->assertNotNull($zombie->end_time);

        $this->assertSame(
            1,
            ChargingSession::query()
                ->where('user_id', $user->id)
                ->where('station_id', $station->id)
                ->where('ocpp_connector_id', 2)
                ->whereNull('end_time')
                ->count()
        );
    }
}
