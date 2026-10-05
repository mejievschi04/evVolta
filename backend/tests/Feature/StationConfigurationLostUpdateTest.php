<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use App\Models\Station;
use App\Services\BillingService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class StationConfigurationLostUpdateTest extends TestCase
{
    use RefreshDatabase;

    private OcppServe $gateway;

    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ocpp.mode' => 'gateway']);

        $this->gateway = app(OcppServe::class);
        $this->gateway->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $this->reflection = new ReflectionClass($this->gateway);
    }

    public function test_meter_values_from_stale_instance_keep_api_connector_change(): void
    {
        $gatewayCopy = $this->station();

        // API (alt proces) elibereaza portul 2 dupa o oprire.
        Station::query()->find($gatewayCopy->id)->markConnectorAvailable(2);

        // Gateway-ul scrie telemetrie pe portul 1 din copia lui veche.
        $this->invoke('onMeterValues', $gatewayCopy, $this->meterValues(1));

        $connectors = $gatewayCopy->fresh()->ocpp_configuration['connectors'];
        $this->assertSame('Available', $connectors[2]['status'], 'API change on port 2 must survive');
        $this->assertSame(7.0, (float) $connectors[1]['live_meter']['power_kw']);
    }

    public function test_status_notification_from_stale_instance_keeps_local_id_tag(): void
    {
        $gatewayCopy = $this->station();

        Station::query()->find($gatewayCopy->id)->rememberLocalIdTag(2, 'abc123');

        $this->invoke('onStatusNotification', $gatewayCopy, [
            'connectorId' => 1,
            'status' => 'SuspendedEV',
            'errorCode' => 'NoError',
        ]);

        $configuration = $gatewayCopy->fresh()->ocpp_configuration;
        $this->assertSame('ABC123', $configuration['local_id_tags'][2]);
        $this->assertSame('SuspendedEV', $configuration['connectors'][1]['status']);
    }

    public function test_in_memory_instance_is_synced_after_atomic_update(): void
    {
        $gatewayCopy = $this->station();
        Station::query()->find($gatewayCopy->id)->markConnectorAvailable(2);

        $gatewayCopy->rememberLocalIdTag(1, 'tag1');

        $this->assertSame('Available', $gatewayCopy->connectorOcppStatus(2));
        $this->assertSame('TAG1', $gatewayCopy->localIdTagForConnector(1));
        $this->assertFalse($gatewayCopy->isDirty());
    }

    public function test_gateway_refreshes_cached_station_on_each_message(): void
    {
        $gatewayCopy = $this->station();
        [$socket, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $clientId = (int) $socket;
        $clients = $this->reflection->getProperty('clients');
        $clients->setAccessible(true);
        $clients->setValue($this->gateway, [$clientId => [
            'socket' => $socket,
            'buffer' => '',
            'handshaken' => true,
            'boot_received' => true,
            'station' => $gatewayCopy,
            'last_command_poll_at' => 0,
            'outbox' => '',
            'close_code' => null,
        ]]);

        Station::query()->whereKey($gatewayCopy->id)->update(['name' => 'Redenumita din backoffice']);

        $this->invoke('handleOcppMessage', $clientId, '[2,"hb-1","Heartbeat",{}]', app(BillingService::class));

        $this->assertSame('Redenumita din backoffice', $clients->getValue($this->gateway)[$clientId]['station']->name);

        fclose($peer);
    }

    private function station(): Station
    {
        return Station::query()->create([
            'name' => 'Statia Lock',
            'location' => 'Test',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => 'LOCK-' . bin2hex(random_bytes(3)),
            'qr_code' => 'LOCK-' . bin2hex(random_bytes(3)),
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'ocpp_configuration' => [
                'NumberOfConnectors' => 2,
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => 'Charging'],
                    2 => ['connectorId' => 2, 'status' => 'Charging'],
                ],
            ],
        ])->fresh();
    }

    private function meterValues(int $connectorId): array
    {
        return [
            'connectorId' => $connectorId,
            'meterValue' => [[
                'timestamp' => now()->toIso8601String(),
                'sampledValue' => [
                    ['value' => '7000', 'measurand' => 'Power.Active.Import', 'unit' => 'W'],
                ],
            ]],
        ];
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $reflectionMethod = $this->reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invoke($this->gateway, ...$args);
    }
}
