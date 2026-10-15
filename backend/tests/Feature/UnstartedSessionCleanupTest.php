<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\Station;
use App\Services\ChargingStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnstartedSessionCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.prepaid_wallet_enabled' => true,
            // The sweep runs inside the OCPP gateway.
            'services.ocpp.mode' => 'gateway',
        ]);
    }

    public function test_never_started_session_is_closed_and_hold_released(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 50]);
        $station = $this->station('Available');
        $session = $this->openSession($user->id, $station, minutesAgo: 20, budget: 100);

        $closed = app(ChargingStopService::class)->closeUnstartedSessions($station);

        $this->assertSame(1, $closed);
        $session = $session->fresh();
        $this->assertNotNull($session->end_time);
        $this->assertSame('NeverStarted', $session->ocpp_stop_reason);
        $this->assertSame(150.0, (float) $user->fresh()->wallet_balance, 'Hold must be returned in full');
        $this->assertFalse($station->fresh()->hasActiveSessionOnConnector(1), 'Port must be free for others');
    }

    public function test_recent_session_is_kept(): void
    {
        $user = $this->createAppUser();
        $station = $this->station('Available');
        $session = $this->openSession($user->id, $station, minutesAgo: 5);

        $this->assertSame(0, app(ChargingStopService::class)->closeUnstartedSessions($station));
        $this->assertNull($session->fresh()->end_time);
    }

    public function test_session_with_recent_or_in_flight_command_is_kept(): void
    {
        $user = $this->createAppUser();
        $station = $this->station('Preparing');
        $retried = $this->openSession($user->id, $station, minutesAgo: 40, connectorId: 1);
        $inFlight = $this->openSession($user->id, $station, minutesAgo: 40, connectorId: 2);

        // User tapped Start again 2 minutes ago on the old row.
        $this->command($station, $retried, OcppCommand::STATUS_ACCEPTED, minutesAgo: 2);
        // Old command still waiting for the charger.
        $this->command($station, $inFlight, OcppCommand::STATUS_SENT, minutesAgo: 30);

        $this->assertSame(0, app(ChargingStopService::class)->closeUnstartedSessions($station));
        $this->assertNull($retried->fresh()->end_time);
        $this->assertNull($inFlight->fresh()->end_time);
    }

    public function test_charging_or_started_sessions_are_never_touched(): void
    {
        $user = $this->createAppUser();
        $station = $this->station('Charging');
        $charging = $this->openSession($user->id, $station, minutesAgo: 40, connectorId: 1);
        $started = $this->openSession($user->id, $station, minutesAgo: 40, connectorId: 2, transactionId: '77');

        $this->assertSame(0, app(ChargingStopService::class)->closeUnstartedSessions($station));
        $this->assertNull($charging->fresh()->end_time);
        $this->assertNull($started->fresh()->end_time);
    }

    public function test_simulator_mode_never_closes_sessions(): void
    {
        config(['services.ocpp.mode' => 'simulator']);
        $user = $this->createAppUser();
        $station = $this->station('Available');
        $session = $this->openSession($user->id, $station, minutesAgo: 40);

        $this->assertSame(0, app(ChargingStopService::class)->closeUnstartedSessions($station));
        $this->assertNull($session->fresh()->end_time);
    }

    private function station(string $connectorStatus): Station
    {
        return Station::query()->create([
            'name' => 'VOLTA '.Str::random(4),
            'location' => 'Chisinau',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => 'CP-'.Str::random(6),
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'ocpp_configuration' => [
                'connectors' => [
                    1 => ['connectorId' => 1, 'status' => $connectorStatus],
                    2 => ['connectorId' => 2, 'status' => $connectorStatus],
                ],
            ],
        ]);
    }

    private function openSession(
        int $userId,
        Station $station,
        int $minutesAgo,
        float $budget = 0,
        int $connectorId = 1,
        ?string $transactionId = null,
    ): ChargingSession {
        return ChargingSession::query()->create([
            'user_id' => $userId,
            'station_id' => $station->id,
            'ocpp_connector_id' => $connectorId,
            'ocpp_transaction_id' => $transactionId,
            'start_source' => 'app',
            'start_time' => now()->subMinutes($minutesAgo),
            'kwh_consumed' => 0,
            'charge_budget' => $budget,
        ]);
    }

    private function command(Station $station, ChargingSession $session, string $status, int $minutesAgo): void
    {
        $command = OcppCommand::query()->create([
            'station_id' => $station->id,
            'charging_session_id' => $session->id,
            'message_uid' => (string) Str::uuid(),
            'action' => 'RemoteStartTransaction',
            'status' => $status,
            'payload' => ['connectorId' => (int) $session->ocpp_connector_id],
        ]);
        $command->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->saveQuietly();
    }
}
