<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\Station;
use App\Models\User;
use App\Services\BillingService;
use App\Services\OcppService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class OcppLocalStartPolicyTest extends TestCase
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

    public function test_local_start_with_user_tag_without_app_session_is_rejected(): void
    {
        $station = $this->station();
        $user = $this->createAppUser(['wallet_balance' => 5]);

        $response = $this->startTransaction($station, OcppService::idTagForUser($user));

        $this->assertSame('Invalid', $response['idTagInfo']['status']);
        $this->assertSame(0, $response['transactionId']);
        $this->assertSame(0, ChargingSession::query()->count());
    }

    public function test_unlinked_card_without_sessions_is_rejected_and_creates_nothing(): void
    {
        $station = $this->station();

        $response = $this->startTransaction($station, 'A5CD0CBD');

        $this->assertSame('Invalid', $response['idTagInfo']['status']);
        $this->assertSame(0, ChargingSession::query()->count());
    }

    public function test_app_session_still_starts_with_user_tag(): void
    {
        $station = $this->station();
        $user = $this->createAppUser(['wallet_balance' => 500]);
        $session = $this->pendingAppSession($station, $user);

        $response = $this->startTransaction($station, OcppService::idTagForUser($user));

        $this->assertSame('Accepted', $response['idTagInfo']['status']);
        $this->assertSame($session->id, $response['transactionId']);
        $this->assertSame((string) $session->id, $session->fresh()->ocpp_transaction_id);
        $this->assertSame(1, ChargingSession::query()->count());
    }

    public function test_local_start_allowed_when_explicitly_enabled(): void
    {
        config(['services.ocpp.allow_local_user_start' => true]);
        $station = $this->station();
        $user = $this->createAppUser();

        $response = $this->startTransaction($station, OcppService::idTagForUser($user));

        $this->assertSame('Accepted', $response['idTagInfo']['status']);
        $this->assertSame('ocpp', ChargingSession::query()->sole()->start_source);
    }

    public function test_stop_of_rejected_card_transaction_does_not_close_waiting_app_session(): void
    {
        $station = $this->station();
        $user = $this->createAppUser(['wallet_balance' => 500]);
        $session = $this->pendingAppSession($station, $user);

        $this->invoke('onStopTransaction', $station, [
            'transactionId' => 0,
            'connectorId' => 1,
            'meterStop' => 0,
            'timestamp' => now()->toIso8601String(),
            'reason' => 'Local',
            'idTag' => 'A5CD0CBD',
        ], app(BillingService::class));

        $this->assertNull($session->fresh()->end_time, 'Card stop must not close another user\'s pending session');
    }

    public function test_stop_with_zero_transaction_still_closes_started_session_after_reset(): void
    {
        $station = $this->station();
        $user = $this->createAppUser(['wallet_balance' => 500]);
        $session = $this->pendingAppSession($station, $user);
        $session->update([
            'ocpp_transaction_id' => (string) $session->id,
            'meter_start_kwh' => 10.0,
        ]);
        OcppCommand::query()->create([
            'station_id' => $station->id,
            'charging_session_id' => $session->id,
            'message_uid' => (string) Str::uuid(),
            'action' => 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_ACCEPTED,
            'payload' => ['connectorId' => 1, 'idTag' => OcppService::idTagForUser($user)],
            'acknowledged_at' => now(),
        ]);

        $this->invoke('onStopTransaction', $station, [
            'transactionId' => 0,
            'connectorId' => 1,
            'meterStop' => 12500,
            'timestamp' => now()->toIso8601String(),
            'reason' => 'SoftReset',
        ], app(BillingService::class));

        $session->refresh();
        $this->assertNotNull($session->end_time);
        $this->assertSame(2.5, (float) $session->kwh_consumed);
    }

    // --- helpers -----------------------------------------------------------

    private function station(): Station
    {
        return Station::query()->create([
            'name' => 'Statia Card',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => 'CARD-' . bin2hex(random_bytes(3)),
            'qr_code' => 'CARD-' . bin2hex(random_bytes(3)),
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_ocpp_message_at' => now(),
            'ocpp_configuration' => [
                'NumberOfConnectors' => 1,
                'connectors' => [1 => ['connectorId' => 1, 'status' => 'Preparing']],
            ],
        ]);
    }

    private function pendingAppSession(Station $station, User $user): ChargingSession
    {
        return ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'ocpp_id_tag' => OcppService::idTagForUser($user),
            'start_source' => 'app',
            'start_time' => now(),
            'kwh_consumed' => 0,
        ]);
    }

    private function startTransaction(Station $station, string $idTag): array
    {
        return $this->invoke('onStartTransaction', $station, [
            'connectorId' => 1,
            'idTag' => $idTag,
            'meterStart' => 10000,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $reflectionMethod = $this->reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invoke($this->gateway, ...$args);
    }
}
