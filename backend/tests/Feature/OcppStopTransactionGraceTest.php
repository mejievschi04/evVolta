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
            'services.ocpp.suspended_ev_stop_seconds' => 120,
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

    public function test_late_stop_transaction_with_different_meter_is_audited(): void
    {
        [$station, $session] = $this->chargingSession();

        $this->statusNotification($station, 'Finishing');
        $this->setClients($station->fresh());
        $this->travel(61)->seconds();
        $this->resetSweepThrottle();
        $this->invoke('finalizeReleasedSessions');
        $this->assertSame(104.0, (float) $session->fresh()->meter_stop_kwh, 'Billed from last MeterValues');

        // The real final meter arrives afterwards.
        $this->invoke('onStopTransaction', $station->fresh(), [
            'transactionId' => (int) $session->ocpp_transaction_id,
            'connectorId' => 1,
            'meterStop' => 105500,
            'timestamp' => now()->toIso8601String(),
            'reason' => 'EVDisconnected',
        ], app(BillingService::class));

        $audit = \App\Models\AuditLog::query()->where('action', 'charging.meter_stop_late')->first();
        $this->assertNotNull($audit);
        $this->assertSame($session->id, (int) $audit->subject_id);
        $this->assertEqualsWithDelta(1.5, $audit->metadata['difference_kwh'], 0.001);
        $this->assertSame(4.0, (float) $session->fresh()->kwh_consumed, 'Billing itself is not changed');
    }

    public function test_late_stop_transaction_matching_billed_meter_is_not_audited(): void
    {
        [$station, $session] = $this->chargingSession();

        $this->statusNotification($station, 'Finishing');
        $this->setClients($station->fresh());
        $this->travel(61)->seconds();
        $this->resetSweepThrottle();
        $this->invoke('finalizeReleasedSessions');

        $this->invoke('onStopTransaction', $station->fresh(), [
            'transactionId' => (int) $session->ocpp_transaction_id,
            'connectorId' => 1,
            'meterStop' => 104000,
            'timestamp' => now()->toIso8601String(),
        ], app(BillingService::class));

        $this->assertDatabaseMissing('audit_logs', ['action' => 'charging.meter_stop_late']);
    }

    public function test_gateway_loop_closes_unstarted_sessions_of_connected_stations(): void
    {
        $user = $this->createAppUser();
        $station = Station::query()->create([
            'name' => 'Statia Unstarted',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => 'UNST-' . bin2hex(random_bytes(3)),
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_ocpp_message_at' => now(),
            'ocpp_configuration' => [
                'NumberOfConnectors' => 1,
                'connectors' => [1 => ['connectorId' => 1, 'status' => 'Available']],
            ],
        ]);
        $session = ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'start_time' => now()->subMinutes(20),
            'start_source' => 'app',
            'kwh_consumed' => 0,
        ]);

        $this->setClients($station->fresh());
        $this->invoke('closeUnstartedSessions');

        $this->assertSame('NeverStarted', $session->fresh()->ocpp_stop_reason);
    }

    public function test_suspended_ev_stops_at_two_minutes_without_duplicate_commands_and_bills_final_meter(): void
    {
        [$station, $session] = $this->chargingSession();
        $this->travelTo(now()->startOfSecond());
        $this->statusNotification($station, 'SuspendedEV');
        $this->setClients($station->fresh());
        $this->travel(119)->seconds();
        $this->sweepSuspended($station);
        $this->assertSame(0, $this->remoteStopCount($session));

        // Repeated notifications must not postpone the original deadline.
        $this->statusNotification($station, 'SuspendedEV');
        $this->travel(1)->seconds();
        $this->sweepSuspended($station);
        $this->sweepSuspended($station);
        $this->assertSame(1, $this->remoteStopCount($session));
        $this->assertNull($session->fresh()->end_time, 'Wait for the station final meter');
        $command = \App\Models\OcppCommand::query()->where('charging_session_id', $session->id)
            ->where('action', 'RemoteStopTransaction')->firstOrFail();
        $this->assertSame((int) $session->ocpp_transaction_id, $command->payload['transactionId']);
        $command->update(['status' => \App\Models\OcppCommand::STATUS_ACCEPTED, 'acknowledged_at' => now()]);
        $this->sweepSuspended($station);
        $this->assertSame(1, $this->remoteStopCount($session));

        $this->invoke('onStopTransaction', $station->fresh(), [
            'transactionId' => (int) $session->ocpp_transaction_id,
            'connectorId' => 1, 'meterStop' => 105500,
            'timestamp' => now()->toIso8601String(), 'reason' => 'Remote',
        ], app(BillingService::class));
        $this->assertNotNull($session->fresh()->end_time);
        $this->assertSame(5.5, (float) $session->fresh()->kwh_consumed);
        $this->assertDatabaseHas('invoices', ['source_session_id' => $session->id, 'total_kwh' => 5.5]);
    }

    public function test_charging_cancels_suspended_timer_and_next_pause_starts_a_new_timer(): void
    {
        [$station, $session] = $this->chargingSession();
        $this->travelTo(now()->startOfSecond());
        $this->statusNotification($station, 'SuspendedEV');
        $this->setClients($station->fresh());
        $this->travel(100)->seconds();
        $this->statusNotification($station, 'Charging');
        $this->travel(30)->seconds();
        $this->sweepSuspended($station);
        $this->assertSame(0, $this->remoteStopCount($session));
        $this->assertArrayNotHasKey('suspended_ev_since', $station->fresh()->ocpp_configuration['connectors'][1]);
        $this->statusNotification($station, 'SuspendedEV');
        $this->travel(119)->seconds();
        $this->sweepSuspended($station);
        $this->assertSame(0, $this->remoteStopCount($session));
        $this->travel(1)->seconds();
        $this->sweepSuspended($station);
        $this->assertSame(1, $this->remoteStopCount($session));
    }

    public function test_suspended_evse_and_other_connectors_are_not_stopped(): void
    {
        [$station, $session] = $this->chargingSession();
        $this->statusNotification($station, 'SuspendedEVSE');
        $this->setClients($station->fresh());
        $this->travel(121)->seconds();
        $this->sweepSuspended($station);
        $this->assertSame(0, $this->remoteStopCount($session));
        $this->statusNotification($station, 'Charging');
        $this->invoke('onStatusNotification', $station->fresh(), ['connectorId' => 2, 'status' => 'SuspendedEV', 'errorCode' => 'NoError']);
        $this->travel(121)->seconds();
        $this->sweepSuspended($station);
        $this->assertSame(0, $this->remoteStopCount($session));
    }

    public function test_suspended_sweep_ignores_unconfirmed_new_and_closed_sessions_and_can_be_disabled(): void
    {
        [$station, $session] = $this->chargingSession();
        $session->update(['ocpp_transaction_id' => null, 'kwh_consumed' => 0, 'meter_start_kwh' => null, 'live_metrics' => []]);
        $this->statusNotification($station, 'SuspendedEV');
        $this->setClients($station->fresh());
        $this->travel(121)->seconds();
        $this->sweepSuspended($station);
        $this->assertSame(0, $this->remoteStopCount($session));
        $session->update(['ocpp_transaction_id' => '98765', 'start_time' => now()]);
        $this->sweepSuspended($station);
        $this->assertSame(0, $this->remoteStopCount($session));
        $this->travel(120)->seconds();
        config(['services.ocpp.suspended_ev_stop_seconds' => 0]);
        $this->sweepSuspended($station);
        $this->assertSame(0, $this->remoteStopCount($session));
        config(['services.ocpp.suspended_ev_stop_seconds' => 120]);
        $session->update(['end_time' => now()]);
        $this->sweepSuspended($station);
        $this->assertSame(0, $this->remoteStopCount($session));
    }

    public function test_stale_or_disconnected_stations_are_not_stopped_until_connected_again(): void
    {
        [$station, $session] = $this->chargingSession();
        $this->statusNotification($station, 'SuspendedEV');
        $this->setClients($station->fresh());
        $this->travel(121)->seconds();
        $this->sweepSuspended();
        $this->assertSame(0, $this->remoteStopCount($session));
        $this->assertNull($session->fresh()->end_time);

        $station->update(['ocpp_connection_status' => Station::OCPP_CONNECTION_DISCONNECTED, 'last_ocpp_message_at' => now()]);
        $this->sweepSuspended();
        $this->assertSame(0, $this->remoteStopCount($session));

        $this->sweepSuspended($station);
        $this->assertSame(1, $this->remoteStopCount($session));
    }

    public function test_auto_stop_is_terminal_and_next_charge_has_a_new_session(): void
    {
        [$station, $session] = $this->chargingSession();
        config(['billing.prepaid_wallet_enabled' => false]);
        $this->statusNotification($station, 'SuspendedEV');
        $this->setClients($station->fresh());
        $this->travel(121)->seconds();
        $this->sweepSuspended($station);
        $this->assertTrue($session->fresh()->stoppedAfterEvPause());
        $this->actingAs($session->user, 'api');
        $this->getJson("/api/sessions/{$session->id}/live")->assertOk()->assertJsonPath('can_resume', false);
        $this->postJson('/api/charging/resume', ['station_id' => $station->id, 'session_id' => $session->id, 'connector_id' => 1])
            ->assertStatus(422)->assertJsonPath('message', 'Sesiunea a fost oprita automat dupa pauza masinii. Asteapta finalizarea opririi, apoi porneste o sesiune noua.');

        // Even Charging after the deadline cannot reopen the session or cancel its stop.
        $this->statusNotification($station, 'Charging');
        $stop = \App\Models\OcppCommand::query()->where('charging_session_id', $session->id)
            ->where('action', 'RemoteStopTransaction')->firstOrFail();
        $stop->update(['status' => \App\Models\OcppCommand::STATUS_REJECTED]);
        $this->sweepSuspended($station);
        $this->assertSame(2, $this->remoteStopCount($session));
        $this->postJson('/api/charging/start', ['station_id' => $station->id, 'connector_id' => 1])->assertStatus(422);
        $result = $this->invoke('onStartTransaction', $station->fresh(), [
            'connectorId' => 1, 'idTag' => \App\Services\OcppService::idTagForUser($session->user),
            'meterStart' => 105500,
        ]);
        $this->assertSame('Invalid', $result['idTagInfo']['status']);
        $this->assertSame(4.0, $session->fresh()->kwh_consumed);

        $this->invoke('onStopTransaction', $station->fresh(), [
            'transactionId' => (int) $session->ocpp_transaction_id, 'connectorId' => 1,
            'meterStop' => 105500, 'timestamp' => now()->toIso8601String(), 'reason' => 'Remote',
        ], app(BillingService::class));
        $this->assertNotNull($session->fresh()->end_time);
        $this->assertTrue($session->fresh()->stoppedAfterEvPause());
        $this->assertSame(5.5, $session->fresh()->kwh_consumed);
        $this->statusNotification($station, 'Preparing');
        $this->postJson('/api/charging/resume', ['station_id' => $station->id, 'session_id' => $session->id, 'connector_id' => 1])
            ->assertStatus(422);
        config(['services.ocpp.mode' => 'simulator']);
        $started = $this->postJson('/api/charging/start', ['station_id' => $station->id, 'connector_id' => 1])->assertCreated();
        $this->assertNotSame($session->id, $started->json('session.id'));
        $this->assertDatabaseHas('charging_sessions', ['id' => $started->json('session.id'), 'start_source' => 'app', 'stop_source' => null]);
        $this->assertSame(5.5, $session->fresh()->kwh_consumed);
    }

    private function sweepSuspended(?Station $station = null): void
    {
        if ($station) {
            // Heartbeats continue during an EV pause without resetting its timer.
            $this->invoke('onHeartbeat', $station->fresh());
        }
        $this->reflection->getProperty('lastSuspendedSweepAt')->setValue($this->gateway, 0.0);
        $this->invoke('stopSuspendedEvSessions');
    }

    private function remoteStopCount(ChargingSession $session): int
    {
        return \App\Models\OcppCommand::query()->where('charging_session_id', $session->id)
            ->whereIn('action', ['RemoteStopTransaction', 'RequestStopTransaction'])->count();
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
