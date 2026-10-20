<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChargingStopGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ocpp.mode' => 'gateway',
        ]);
    }

    public function test_app_stop_closes_session_after_station_confirmation_and_settles_only_once(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);
        $user = $this->createAppUser(['wallet_balance' => 400]);
        $station = Station::query()->create([
            'name' => 'VOLTA Stop',
            'location' => 'Chisinau',
            'status' => Station::STATUS_CHARGING,
            'qr_code' => 'station:stop-complete',
            'ocpp_identity' => 'stop-complete',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => ['connectors' => [
                1 => ['connectorId' => 1, 'status' => 'Available'],
                2 => ['connectorId' => 2, 'status' => 'Charging'],
            ]],
        ]);
        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_transaction_id' => '42',
            'start_time' => now()->subMinutes(10),
            'meter_start_kwh' => 10,
            'kwh_consumed' => 1.5,
            'price_per_kwh' => 6,
            'charge_budget' => 100,
        ]);
        $this->actingAs($user, 'api')->postJson('/api/charging/stop', [
            'station_id' => $station->id, 'session_id' => $session->id, 'connector_id' => 2,
        ])->assertOk()->assertJsonPath('status', 'stopping');

        $gateway = app(\App\Console\Commands\OcppServe::class);
        $gateway->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\BufferedOutput(),
        ));
        $method = new \ReflectionMethod($gateway, 'onStopTransaction');
        $payload = [
            'transactionId' => 42, 'connectorId' => 2,
            'meterStop' => 12000, 'timestamp' => now()->toIso8601String(), 'reason' => 'Remote',
        ];
        $method->invoke($gateway, $station->fresh(), $payload, app(\App\Services\BillingService::class));
        $this->assertNotNull($session->fresh()->end_time);
        $this->assertSame(2.0, $session->fresh()->kwh_consumed);
        $this->assertSame(488.0, (float) $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('invoices', ['source_session_id' => $session->id, 'total_amount' => 12]);
        $this->getJson('/api/sessions/active')->assertOk()->assertExactJson([]);
        $this->getJson('/api/sessions')->assertOk()->assertJsonPath('0.id', $session->id);

        $method->invoke($gateway, $station->fresh(), $payload, app(\App\Services\BillingService::class));
        $this->assertSame(488.0, (float) $user->fresh()->wallet_balance);
        $this->assertSame(1, \App\Models\Invoice::query()->where('source_session_id', $session->id)->count());
        $this->assertDatabaseMissing('ocpp_commands', ['station_id' => $station->id, 'action' => 'Reset']);
    }

    public function test_gateway_stop_queues_remote_command_without_closing_session(): void
    {
        $user = $this->createAppUser([
            'email' => 'driver@example.test',
            'name' => 'Driver One',
            'wallet_balance' => 500,
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chișinău',
            'status' => Station::STATUS_CHARGING,
            'qr_code' => 'station:volta-1',
            'ocpp_identity' => 'volta-1',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'meter_value_kwh' => 12.5,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'start_time' => now()->subMinutes(10),
            'meter_start_kwh' => 10.2,
            'kwh_consumed' => 2.3,
            'ocpp_transaction_id' => '42',
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/stop', [
                'station_id' => $station->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'stopping')
            ->assertJsonPath('session.id', $session->id);

        $this->assertDatabaseHas('charging_sessions', [
            'id' => $session->id,
            'end_time' => null,
        ]);

        $this->assertDatabaseHas('ocpp_commands', [
            'station_id' => $station->id,
            'charging_session_id' => $session->id,
            'action' => 'RemoteStopTransaction',
        ]);
    }

    public function test_gateway_stop_without_transaction_id_closes_locally_without_fake_remote_stop(): void
    {
        $user = $this->createAppUser([
            'email' => 'driver@example.test',
            'name' => 'Driver One',
            'wallet_balance' => 500,
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Chișinău',
            'status' => Station::STATUS_CHARGING,
            'qr_code' => 'station:volta-1',
            'ocpp_identity' => 'volta-1',
            'ocpp_version' => '1.6J',
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
            'start_time' => now()->subMinutes(2),
            'kwh_consumed' => 0,
            'ocpp_transaction_id' => null,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/stop', [
                'station_id' => $station->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertNotNull($session->fresh()->end_time);
        $this->assertDatabaseMissing('ocpp_commands', [
            'station_id' => $station->id,
            'action' => 'RemoteStopTransaction',
        ]);
        $this->assertSame(0, $session->fresh()->remoteStopTransactionId());
    }
}
