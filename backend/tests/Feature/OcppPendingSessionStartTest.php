<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use App\Models\ChargingSession;
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
        $this->assertSame('A5CD0CBD', $session->ocpp_id_tag);
    }

    public function test_authorize_accepts_unknown_tag_when_app_session_is_pending(): void
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
            'start_source' => 'app',
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);

        $response = $this->invokeAuthorize($station, ['idTag' => 'A5CD0CBD']);

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
