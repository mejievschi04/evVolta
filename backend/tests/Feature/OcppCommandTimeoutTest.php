<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use App\Models\OcppCommand;
use App\Models\Station;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class OcppCommandTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_unanswered_sent_commands_fail_after_timeout_with_dependents(): void
    {
        $station = $this->station();

        $stale = $this->command($station, 'Reset', OcppCommand::STATUS_SENT, ['sent_at' => now()->subMinutes(5)]);
        $dependent = $this->command($station, 'RemoteStartTransaction', OcppCommand::STATUS_PENDING, [
            'depends_on_command_id' => $stale->id,
        ]);
        $recent = $this->command($station, 'TriggerMessage', OcppCommand::STATUS_SENT, ['sent_at' => now()->subSeconds(5)]);

        $failed = OcppCommand::failUnanswered('Timeout', null, now()->subSeconds(60));

        $this->assertSame(1, $failed);
        $this->assertSame(OcppCommand::STATUS_FAILED, $stale->fresh()->status);
        $this->assertSame(OcppCommand::STATUS_FAILED, $dependent->fresh()->status);
        $this->assertSame(OcppCommand::STATUS_SENT, $recent->fresh()->status);
    }

    public function test_accepted_remote_stop_only_blocks_within_window(): void
    {
        $station = $this->station();

        $old = $this->command($station, 'RemoteStopTransaction', OcppCommand::STATUS_ACCEPTED, [
            'acknowledged_at' => now()->subMinutes(10),
        ]);
        $this->assertFalse(OcppCommand::query()->whereKey($old->id)->awaitingRemoteStop()->exists());

        $fresh = $this->command($station, 'RemoteStopTransaction', OcppCommand::STATUS_ACCEPTED, [
            'acknowledged_at' => now()->subMinute(),
        ]);
        $this->assertTrue(OcppCommand::query()->whereKey($fresh->id)->awaitingRemoteStop()->exists());

        $sent = $this->command($station, 'RemoteStopTransaction', OcppCommand::STATUS_SENT, [
            'sent_at' => now()->subMinutes(10),
        ]);
        $this->assertTrue(OcppCommand::query()->whereKey($sent->id)->awaitingRemoteStop()->exists());
    }

    public function test_disconnect_fails_sent_commands_for_station(): void
    {
        $station = $this->station();
        $sent = $this->command($station, 'RemoteStopTransaction', OcppCommand::STATUS_SENT, ['sent_at' => now()]);
        $pending = $this->command($station, 'RemoteStartTransaction', OcppCommand::STATUS_PENDING);

        [$gateway, $reflection, $clientId] = $this->gatewayWithClient($station);

        $disconnect = $reflection->getMethod('disconnectClient');
        $disconnect->setAccessible(true);
        $disconnect->invoke($gateway, $clientId);

        $this->assertSame(OcppCommand::STATUS_FAILED, $sent->fresh()->status);
        $this->assertSame(OcppCommand::STATUS_PENDING, $pending->fresh()->status);
    }

    public function test_command_stays_pending_when_socket_write_fails(): void
    {
        $station = $this->station();
        $pending = $this->command($station, 'RemoteStopTransaction', OcppCommand::STATUS_PENDING);

        [$gateway, $reflection, $clientId, $socket] = $this->gatewayWithClient($station);
        fclose($socket);

        $flush = $reflection->getMethod('flushPendingCommands');
        $flush->setAccessible(true);
        $flush->invoke($gateway);

        $this->assertSame(OcppCommand::STATUS_PENDING, $pending->fresh()->status);
        $this->assertNull($pending->fresh()->sent_at);
    }

    private function gatewayWithClient(Station $station): array
    {
        [$socket, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fclose($peer);

        $gateway = app(OcppServe::class);
        $gateway->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $reflection = new ReflectionClass($gateway);
        $clients = $reflection->getProperty('clients');
        $clients->setAccessible(true);

        $clientId = (int) $socket;
        $clients->setValue($gateway, [
            $clientId => [
                'socket' => $socket,
                'buffer' => '',
                'handshaken' => true,
                'boot_received' => true,
                'station' => $station,
                'last_command_poll_at' => 0,
            ],
        ]);

        return [$gateway, $reflection, $clientId, $socket];
    }

    private function station(): Station
    {
        return Station::query()->create([
            'name' => 'Statia Timeout',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => 'TIMEOUT-' . Str::random(6),
            'qr_code' => 'TIMEOUT-' . Str::random(6),
        ]);
    }

    private function command(Station $station, string $action, string $status, array $extra = []): OcppCommand
    {
        return OcppCommand::query()->create(array_merge([
            'station_id' => $station->id,
            'message_uid' => (string) Str::uuid(),
            'action' => $action,
            'status' => $status,
            'payload' => ['connectorId' => 1],
        ], $extra));
    }
}
