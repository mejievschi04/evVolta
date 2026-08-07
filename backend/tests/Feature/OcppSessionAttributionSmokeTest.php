<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\Station;
use App\Models\User;
use App\Services\OcppService;
use App\Services\SessionEnergyService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Brut smoke: Finishing alt user + RemoteStart + RFID local + flash energie din sesiunea veche.
 */
class OcppSessionAttributionSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_start_flow_keeps_owner_and_ignores_energy_flash(): void
    {
        Config::set('services.ocpp.mode', 'gateway');

        $previousUser = $this->createPersonalUser([
            'name' => 'Ghenadie',
            'email' => 'director@volta.md',
        ]);
        $startingUser = $this->createPersonalUser([
            'name' => 'test',
            'email' => 'webmaster@volta.md',
        ]);

        $station = Station::query()->create([
            'name' => 'Statia Volta 1',
            'location' => 'Depou',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => 'smoke-volta-1',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'NumberOfConnectors' => 1,
                'local_id_tags' => [1 => 'A5CD0CBD'],
                'connectors' => [
                    1 => [
                        'connectorId' => 1,
                        'status' => 'Finishing',
                        'live_meter' => [
                            'energy_kwh' => 34.941,
                            'power_kw' => 0,
                        ],
                    ],
                ],
            ],
        ]);

        // 1) Sesiune Finishing a altui user — trebuie lasata in pace.
        $finishing = ChargingSession::query()->create([
            'user_id' => $previousUser->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'ocpp_id_tag' => OcppService::idTagForUser($previousUser),
            'start_source' => 'app',
            'start_time' => now()->subHours(4),
            'kwh_consumed' => 34.941,
            'meter_start_kwh' => 0,
            'live_metrics' => [
                'energy_kwh' => 34.941,
                'power_kw' => 0,
            ],
        ]);
        $finishing->update(['ocpp_transaction_id' => (string) $finishing->id]);
        $finishingSnapshot = $finishing->fresh()->only([
            'user_id',
            'kwh_consumed',
            'ocpp_transaction_id',
            'ocpp_id_tag',
        ]);

        // 2) Userul curent apasa Start in app → sesiune pending + RemoteStart.
        $pending = ChargingSession::query()->create([
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
            'charging_session_id' => $pending->id,
            'message_uid' => 'smoke-remote-start',
            'action' => 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_ACCEPTED,
            'payload' => [
                'connectorId' => 1,
                'idTag' => 'A5CD0CBD', // RFID local pe EU1060
            ],
            'acknowledged_at' => now(),
        ]);

        // 3) Inainte de StartTransaction, stația mai trimite registrul vechi (~35 kWh).
        $this->invokeMeterValues($station, [
            'connectorId' => 1,
            'transactionId' => 0,
            'meterValue' => [[
                'timestamp' => now()->toIso8601String(),
                'sampledValue' => [
                    [
                        'value' => '35080',
                        'unit' => 'Wh',
                        'measurand' => 'Energy.Active.Import.Register',
                    ],
                    [
                        'value' => '3.69',
                        'unit' => 'kW',
                        'measurand' => 'Power.Active.Import',
                    ],
                ],
            ]],
        ]);

        $pending->refresh();
        $this->assertSame(
            0.0,
            (float) $pending->kwh_consumed,
            'Flash-ul ~35 kWh din sesiunea anterioara nu trebuie afisat pe sesiunea noua'
        );
        $this->assertSame(
            $startingUser->id,
            $pending->user_id,
            'Pending trebuie sa ramana pe userul care a pornit'
        );

        // 4) StartTransaction cu RFID local (fara VOLTA{userId}).
        $startResponse = $this->invokeStartTransaction($station, [
            'connectorId' => 1,
            'idTag' => 'A5CD0CBD',
            'meterStart' => 0,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->assertSame('Accepted', $startResponse['idTagInfo']['status']);
        $this->assertSame(
            $pending->id,
            $startResponse['transactionId'],
            'StartTransaction trebuie legat de RemoteStart-ul userului curent, nu de Finishing'
        );

        $pending->refresh();
        $finishing->refresh();

        $this->assertSame($startingUser->id, $pending->user_id);
        $this->assertSame(OcppService::idTagForUser($startingUser), $pending->ocpp_id_tag);
        $this->assertSame((string) $pending->id, $pending->ocpp_transaction_id);
        $this->assertSame(0.0, (float) $pending->kwh_consumed);

        $this->assertSame($finishingSnapshot['user_id'], $finishing->user_id);
        $this->assertSame((float) $finishingSnapshot['kwh_consumed'], (float) $finishing->kwh_consumed);
        $this->assertSame($finishingSnapshot['ocpp_transaction_id'], $finishing->ocpp_transaction_id);
        $this->assertSame($finishingSnapshot['ocpp_id_tag'], $finishing->ocpp_id_tag);

        // 5) Dupa ce contorul de sesiune e relativ, energia reala (mica) e acceptata.
        $pending->update(['start_time' => now()->subMinutes(3)]);
        $this->invokeMeterValues($station, [
            'connectorId' => 1,
            'transactionId' => (string) $pending->id,
            'meterValue' => [[
                'timestamp' => now()->toIso8601String(),
                'sampledValue' => [
                    [
                        'value' => '1987',
                        'unit' => 'Wh',
                        'measurand' => 'Energy.Active.Import.Register',
                    ],
                    [
                        'value' => '3.69',
                        'unit' => 'kW',
                        'measurand' => 'Power.Active.Import',
                    ],
                ],
            ]],
        ]);

        $pending->refresh();
        $delivered = app(SessionEnergyService::class)->telemetryKwhDelivered($pending);

        $this->assertSame(1.987, $delivered);
        $this->assertSame($startingUser->id, $pending->user_id);
        $this->assertSame(34.941, (float) $finishing->fresh()->kwh_consumed);

        fwrite(STDERR, sprintf(
            "\n[SMOKE OK] starter=%s finishing_owner=%s pending_kwh=%s finishing_kwh=%s tx=%s\n",
            $startingUser->email,
            $previousUser->email,
            $delivered,
            $finishing->fresh()->kwh_consumed,
            $pending->ocpp_transaction_id
        ));
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
}
