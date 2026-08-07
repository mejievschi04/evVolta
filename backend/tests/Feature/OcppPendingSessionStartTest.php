<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\Station;
use App\Models\User;
use App\Services\OcppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Console\OutputStyle;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class OcppPendingSessionStartTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_transaction_prefers_remote_start_session_over_other_users_open_row(): void
    {
        $previousUser = User::factory()->create();
        $startingUser = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        $staleSession = ChargingSession::query()->create([
            'user_id' => $previousUser->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'ocpp_transaction_id' => null,
            'ocpp_id_tag' => 'A5CD0CBD',
            'start_source' => 'app',
            'start_time' => now()->subHour(),
            'kwh_consumed' => 34.941,
            'meter_start_kwh' => 0,
        ]);
        $staleSession->update(['ocpp_transaction_id' => (string) $staleSession->id]);

        $newSession = ChargingSession::query()->create([
            'user_id' => $startingUser->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'ocpp_id_tag' => OcppService::idTagForUser($startingUser),
            'start_source' => 'app',
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        OcppCommand::query()->create([
            'station_id' => $station->id,
            'charging_session_id' => $newSession->id,
            'message_uid' => 'uid-remote-start-1',
            'action' => 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_ACCEPTED,
            'payload' => ['connectorId' => 1, 'idTag' => 'A5CD0CBD'],
            'acknowledged_at' => now(),
        ]);

        $response = $this->invokeStartTransaction($station, [
            'connectorId' => 1,
            'idTag' => 'A5CD0CBD',
            'meterStart' => 35080,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->assertSame($newSession->id, $response['transactionId']);
        $this->assertSame('Accepted', $response['idTagInfo']['status']);

        $newSession->refresh();
        $staleSession->refresh();
        $this->assertSame((string) $newSession->id, $newSession->ocpp_transaction_id);
        $this->assertSame($startingUser->id, $newSession->user_id);
        $this->assertSame((string) $staleSession->id, $staleSession->ocpp_transaction_id);
        $this->assertSame(34.941, (float) $staleSession->kwh_consumed);
    }

    public function test_meter_values_with_zero_transaction_do_not_attach_to_other_users_open_session(): void
    {
        $previousUser = User::factory()->create();
        $startingUser = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        $staleSession = ChargingSession::query()->create([
            'user_id' => $previousUser->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'start_source' => 'app',
            'start_time' => now()->subHour(),
            'kwh_consumed' => 34.9,
            'meter_start_kwh' => 0,
        ]);
        $staleSession->update(['ocpp_transaction_id' => (string) $staleSession->id]);

        $newSession = ChargingSession::query()->create([
            'user_id' => $startingUser->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'start_source' => 'app',
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        OcppCommand::query()->create([
            'station_id' => $station->id,
            'charging_session_id' => $newSession->id,
            'message_uid' => 'uid-remote-start-2',
            'action' => 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_ACCEPTED,
            'payload' => ['connectorId' => 1, 'idTag' => 'A5CD0CBD'],
            'acknowledged_at' => now(),
        ]);

        $this->invokeMeterValues($station, [
            'connectorId' => 1,
            'transactionId' => 0,
            'meterValue' => [[
                'timestamp' => now()->toIso8601String(),
                'sampledValue' => [[
                    'value' => '150',
                    'unit' => 'Wh',
                    'measurand' => 'Energy.Active.Import.Register',
                ]],
            ]],
        ]);

        $newSession->refresh();
        $staleSession->refresh();
        $this->assertSame(0.15, (float) $newSession->kwh_consumed);
        $this->assertSame(34.9, (float) $staleSession->kwh_consumed);
    }

    public function test_start_transaction_does_not_touch_finishing_session_with_energy(): void
    {
        $previousUser = User::factory()->create();
        $startingUser = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Finishing'],
                ],
            ],
        ]);

        $finishingSession = ChargingSession::query()->create([
            'user_id' => $previousUser->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'ocpp_id_tag' => OcppService::idTagForUser($previousUser),
            'start_source' => 'app',
            'start_time' => now()->subHour(),
            'kwh_consumed' => 34.941,
            'meter_start_kwh' => 0,
            'live_metrics' => ['energy_kwh' => 34.941, 'power_kw' => 0],
        ]);
        $finishingSession->update(['ocpp_transaction_id' => (string) $finishingSession->id]);

        $newSession = ChargingSession::query()->create([
            'user_id' => $startingUser->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'ocpp_id_tag' => OcppService::idTagForUser($startingUser),
            'start_source' => 'app',
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        OcppCommand::query()->create([
            'station_id' => $station->id,
            'charging_session_id' => $newSession->id,
            'message_uid' => 'uid-remote-start-finishing',
            'action' => 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_ACCEPTED,
            'payload' => ['connectorId' => 1, 'idTag' => 'A5CD0CBD'],
            'acknowledged_at' => now(),
        ]);

        $beforeKwh = (float) $finishingSession->fresh()->kwh_consumed;
        $beforeTx = $finishingSession->fresh()->ocpp_transaction_id;

        $response = $this->invokeStartTransaction($station, [
            'connectorId' => 1,
            'idTag' => 'A5CD0CBD',
            'meterStart' => 100,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->assertSame($newSession->id, $response['transactionId']);

        $finishingSession->refresh();
        $newSession->refresh();

        $this->assertSame($beforeKwh, (float) $finishingSession->kwh_consumed);
        $this->assertSame($beforeTx, $finishingSession->ocpp_transaction_id);
        $this->assertSame($previousUser->id, $finishingSession->user_id);
        $this->assertSame($startingUser->id, $newSession->user_id);
        $this->assertSame(OcppService::idTagForUser($startingUser), $newSession->ocpp_id_tag);
    }

    public function test_start_transaction_links_local_charger_id_tag_to_pending_app_session(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_id_tag' => OcppService::idTagForUser($user),
            'start_source' => 'app',
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        $response = $this->invokeStartTransaction($station, [
            'connectorId' => 2,
            'idTag' => 'A5CD0CBD',
            'meterStart' => 0,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->assertSame($session->id, $response['transactionId']);
        $this->assertSame('Accepted', $response['idTagInfo']['status']);

        $session->refresh();
        $this->assertSame((string) $session->id, $session->ocpp_transaction_id);
        $this->assertSame(OcppService::idTagForUser($user), $session->ocpp_id_tag);
        $this->assertSame($user->id, $session->user_id);
    }

    public function test_authorize_rejects_unknown_tag_even_when_app_session_is_pending(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_id_tag' => OcppService::idTagForUser($user),
            'start_source' => 'app',
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        $response = $this->invokeAuthorize($station, ['idTag' => 'A5CD0CBD']);

        $this->assertSame('Invalid', $response['idTagInfo']['status']);
    }

    public function test_authorize_accepts_pending_session_id_tag(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        $idTag = OcppService::idTagForUser($user);

        ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_id_tag' => $idTag,
            'start_source' => 'app',
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        $response = $this->invokeAuthorize($station, ['idTag' => $idTag]);

        $this->assertSame('Accepted', $response['idTagInfo']['status']);
    }

    public function test_start_transaction_links_session_when_meter_values_preassigned_transaction_id(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'ocpp_transaction_id' => null,
            'ocpp_id_tag' => 'A5CD0CBD',
            'start_source' => 'app',
            'start_time' => now(),
            'kwh_consumed' => 0.035,
            'meter_start_kwh' => 0.068,
            'live_metrics' => [
                'energy_kwh' => 0.068,
                'power_kw' => 3.52,
            ],
        ]);

        $session->update(['ocpp_transaction_id' => (string) $session->id]);

        $response = $this->invokeStartTransaction($station, [
            'connectorId' => 2,
            'idTag' => 'A5CD0CBD',
            'meterStart' => 0,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->assertSame($session->id, $response['transactionId']);
        $this->assertSame('Accepted', $response['idTagInfo']['status']);

        $session->refresh();
        $this->assertSame(0.068, (float) $session->meter_start_kwh);
        $this->assertSame(0.0, (float) $session->kwh_consumed);
        $this->assertArrayNotHasKey('energy_kwh', $session->live_metrics ?? []);
    }

    public function test_meter_values_ignore_previous_session_lifetime_register_flash(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'ocpp_configuration' => [
                'connectors' => [
                    1 => [
                        'connectorId' => 1,
                        'live_meter' => ['energy_kwh' => 34.941, 'power_kw' => 0],
                    ],
                ],
            ],
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'ocpp_transaction_id' => null,
            'start_source' => 'app',
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        OcppCommand::query()->create([
            'station_id' => $station->id,
            'charging_session_id' => $session->id,
            'message_uid' => 'uid-flash-guard',
            'action' => 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_ACCEPTED,
            'payload' => ['connectorId' => 1, 'idTag' => 'A5CD0CBD'],
            'acknowledged_at' => now(),
        ]);

        $this->invokeMeterValues($station, [
            'connectorId' => 1,
            'transactionId' => 0,
            'meterValue' => [[
                'timestamp' => now()->toIso8601String(),
                'sampledValue' => [[
                    'value' => '34941',
                    'unit' => 'Wh',
                    'measurand' => 'Energy.Active.Import.Register',
                ], [
                    'value' => '3.5',
                    'unit' => 'kW',
                    'measurand' => 'Power.Active.Import',
                ]],
            ]],
        ]);

        $session->refresh();
        $this->assertSame(0.0, (float) $session->kwh_consumed);
        $this->assertArrayNotHasKey('energy_kwh', $session->live_metrics ?? []);
    }

    public function test_meter_values_does_not_preassign_transaction_id_before_start_transaction(): void
    {
        $user = User::factory()->create();
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => '5D419400481F59D750010067',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 2,
            'start_source' => 'app',
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        $this->invokeMeterValues($station, [
            'connectorId' => 2,
            'transactionId' => 0,
            'meterValue' => [[
                'timestamp' => now()->toIso8601String(),
                'sampledValue' => [[
                    'value' => '68.000',
                    'unit' => 'Wh',
                    'measurand' => 'Energy.Active.Import.Register',
                ], [
                    'value' => '3.523',
                    'unit' => 'kW',
                    'measurand' => 'Power.Active.Import',
                ]],
            ]],
        ]);

        $session->refresh();
        $this->assertNull($session->ocpp_transaction_id);
        $this->assertNull($session->meter_start_kwh);
        $this->assertSame(0.068, (float) $session->kwh_consumed);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function invokeMeterValues(Station $station, array $payload): array
    {
        $command = app(OcppServe::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $method = (new ReflectionClass($command))->getMethod('onMeterValues');
        $method->setAccessible(true);

        return $method->invoke($command, $station, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function invokeStartTransaction(Station $station, array $payload): array
    {
        $command = app(OcppServe::class);
        $method = (new ReflectionClass($command))->getMethod('onStartTransaction');
        $method->setAccessible(true);

        return $method->invoke($command, $station, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function invokeAuthorize(Station $station, array $payload): array
    {
        $command = app(OcppServe::class);
        $method = (new ReflectionClass($command))->getMethod('onAuthorize');
        $method->setAccessible(true);

        return $method->invoke($command, $station, $payload);
    }
}
