<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use App\Models\ChargingSession;
use App\Models\Station;
use App\Services\BillingService;
use App\Services\ChargingStopService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class OcppStopTransactionGraceTest extends TestCase
{
    use RefreshDatabase;

    private OcppServe $gateway;

    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ocpp.mode' => 'gateway',
            'services.ocpp.stop_transaction_grace_seconds' => 60,
        ]);

        $this->gateway = app(OcppServe::class);
        $this->gateway->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $this->reflection = new ReflectionClass($this->gateway);
    }

    public function test_released_at_is_set_on_first_release_and_kept_on_repeats(): void
    {
        [$station] = $this->chargingSession();

        $this->travelTo(now());
        $this->statusNotification($station, 'Finishing');
        $first = $station->fresh()->connectorReleasedAt(1);
        $this->assertNotNull($first);

        $this->travel(30)->seconds();
        $this->statusNotification($station, 'Finishing');
        $this->statusNotification($station, 'Available');
        $this->assertTrue($first->equalTo($station->fresh()->connectorReleasedAt(1)), 'Repeated release must not move the clock');

        $this->statusNotification($station, 'Charging');
        $this->assertNull($station->fresh()->connectorReleasedAt(1));
    }

    public function test_finishing_before_stop_transaction_bills_final_meter_value(): void
    {
        [$station, $session] = $this->chargingSession();

        $this->statusNotification($station, 'Finishing');
        $this->assertNull($session->fresh()->end_time, 'Session must wait for StopTransaction');

        $this->invoke('onStopTransaction', $station->fresh(), [
            'transactionId' => (int) $session->ocpp_transaction_id,
            'connectorId' => 1,
            'meterStop' => 105500,
            'timestamp' => now()->toIso8601String(),
            'reason' => 'EVDisconnected',
        ], app(BillingService::class));

        $session->refresh();
        $this->assertNotNull($session->end_time);
        $this->assertSame(5.5, (float) $session->kwh_consumed);
        $this->assertSame(105.5, (float) $session->meter_stop_kwh);
    }

    public function test_sweep_closes_released_session_after_grace_without_stop_transaction(): void
    {
        [$station, $session] = $this->chargingSession();

        $this->statusNotification($station, 'Finishing');
        $this->setClients($station->fresh());

        $this->invoke('finalizeReleasedSessions');
        $this->assertNull($session->fresh()->end_time, 'Still inside grace window');

        $this->travel(61)->seconds();
        $this->resetSweepThrottle();
        $this->invoke('finalizeReleasedSessions');

        $session->refresh();
        $this->assertNotNull($session->end_time);
        $this->assertSame(4.0, (float) $session->kwh_consumed, 'Fallback uses last MeterValues register');
    }

    public function test_sweep_closes_only_the_released_transaction_not_a_newer_pending_start(): void
    {
        [$station, $session] = $this->chargingSession();
        $this->statusNotification($station, 'Finishing');
        $this->travel(61)->seconds();

        $pending = ChargingSession::query()->create([
            'user_id' => $session->user_id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'start_time' => now(),
            'start_source' => 'app',
            'kwh_consumed' => 0,
        ]);

        $this->setClients($station->fresh());
        $this->resetSweepThrottle();
        $this->invoke('finalizeReleasedSessions');

        $this->assertNotNull($session->fresh()->end_time, 'Released transaction must be closed');
        $this->assertNull($pending->fresh()->end_time, 'Newer pending app start must be left alone');
    }

    public function test_app_stop_during_grace_waits_for_station(): void
    {
        [$station, $session] = $this->chargingSession();
        $this->statusNotification($station, 'Finishing');

        $result = app(ChargingStopService::class)->requestStop($session, $station->fresh(), 'app');

        $this->assertSame('stopping', $result['status']);
        $this->assertNull($session->fresh()->end_time);
    }

    public function test_new_start_on_same_port_closes_old_session_without_waiting(): void
    {
        [$station, $session] = $this->chargingSession();
        $this->statusNotification($station, 'Available');

        app(ChargingStopService::class)->reconcileOpenSessionsBeforeStart(
            $station->fresh(),
            (int) $session->user_id,
            1
        );

        $session->refresh();
        $this->assertNotNull($session->end_time, 'Old session must not be reused by the new StartTransaction');
        $this->assertSame(4.0, (float) $session->kwh_consumed);
    }

    public function test_without_release_timestamp_behaviour_is_unchanged(): void
    {
        [$station, $session] = $this->chargingSession();

        $configuration = $station->ocpp_configuration;
        $configuration['connectors'][1]['status'] = 'Finishing';
        $station->update(['ocpp_configuration' => $configuration]);

        $this->assertTrue(app(ChargingStopService::class)->shouldAutoFinalizeOnConnectorRelease(
            $session->fresh(),
            $station->fresh(),
            'Finishing'
        ));
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @return array{0: Station, 1: ChargingSession}
     */
    private function chargingSession(): array
    {
        $user = $this->createAppUser(['wallet_balance' => 500]);

        $station = Station::query()->create([
            'name' => 'Statia Grace',
            'location' => 'Test',
            'status' => Station::STATUS_CHARGING,
            'ocpp_identity' => 'GRACE-' . bin2hex(random_bytes(3)),
            'qr_code' => 'GRACE-' . bin2hex(random_bytes(3)),
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_ocpp_message_at' => now(),
            'ocpp_configuration' => [
                'NumberOfConnectors' => 1,
                'connectors' => [1 => ['connectorId' => 1, 'status' => 'Charging']],
            ],
        ]);

        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'start_time' => now()->subMinutes(30),
            'start_source' => 'app',
            'meter_start_kwh' => 100.0,
            'kwh_consumed' => 4.0,
            'live_metrics' => ['energy_kwh' => 104.0, 'power_kw' => 7.0],
        ]);
        $session->update(['ocpp_transaction_id' => (string) $session->id]);

        return [$station->fresh(), $session->fresh()];
    }

    private function statusNotification(Station $station, string $status): void
    {
        // Gateway-ul tine instanta statiei in memorie; o simulam cu aceeasi instanta.
        $this->invoke('onStatusNotification', $station, [
            'connectorId' => 1,
            'status' => $status,
            'errorCode' => 'NoError',
        ]);
    }

    private function setClients(Station $station): void
    {
        [$socket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $property = $this->reflection->getProperty('clients');
        $property->setAccessible(true);
        $property->setValue($this->gateway, [(int) $socket => [
            'socket' => $socket,
            'buffer' => '',
            'handshaken' => true,
            'boot_received' => true,
            'station' => $station,
            'last_command_poll_at' => 0,
            'outbox' => '',
            'close_code' => null,
        ]]);
    }

    private function resetSweepThrottle(): void
    {
        $property = $this->reflection->getProperty('lastReleasedSweepAt');
        $property->setAccessible(true);
        $property->setValue($this->gateway, 0.0);
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $reflectionMethod = $this->reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invoke($this->gateway, ...$args);
    }
}
