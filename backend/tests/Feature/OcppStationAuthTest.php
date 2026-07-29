<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use App\Models\Station;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class OcppStationAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_handshake_without_password_still_works_when_auth_not_configured(): void
    {
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => 'station-auth-1',
        ]);

        [$handshaken, $response] = $this->performHandshakeFor($station);

        $this->assertTrue($handshaken);
        $this->assertStringContainsString('101 Switching Protocols', $response);
    }

    public function test_handshake_rejects_missing_basic_auth_when_password_configured(): void
    {
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => 'station-auth-2',
            'ocpp_auth_password' => Hash::make('super-secret'),
        ]);

        [$handshaken, $response] = $this->performHandshakeFor($station);

        $this->assertFalse($handshaken);
        $this->assertStringContainsString('401 Unauthorized', $response);
    }

    public function test_handshake_accepts_valid_basic_auth(): void
    {
        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => 'station-auth-3',
            'ocpp_auth_password' => Hash::make('super-secret'),
        ]);

        $auth = 'Basic '.base64_encode('station-auth-3:super-secret');
        [$handshaken, $response] = $this->performHandshakeFor($station, $auth);

        $this->assertTrue($handshaken);
        $this->assertStringContainsString('101 Switching Protocols', $response);
    }

    public function test_handshake_rate_limit_rejects_aggressive_reconnects(): void
    {
        config([
            'services.ocpp.handshake_rate_limit' => 2,
            'services.ocpp.handshake_rate_window_seconds' => 60,
        ]);

        $station = Station::query()->create([
            'name' => 'VOLTA 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => 'station-rate-1',
        ]);

        $command = app(OcppServe::class);
        [$firstOk] = $this->performHandshakeFor($station, null, $command);
        [$secondOk] = $this->performHandshakeFor($station, null, $command);
        [$thirdOk, $thirdResponse] = $this->performHandshakeFor($station, null, $command);

        $this->assertTrue($firstOk);
        $this->assertTrue($secondOk);
        $this->assertFalse($thirdOk);
        $this->assertStringContainsString('429 Too Many Requests', $thirdResponse);
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function performHandshakeFor(Station $station, ?string $authorization = null, ?OcppServe $command = null): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);

        [$serverSocket, $clientSocket] = $sockets;
        stream_set_blocking($serverSocket, false);
        stream_set_blocking($clientSocket, false);

        $key = base64_encode(random_bytes(16));
        $httpRequest = "GET /ocpp/{$station->ocpp_identity} HTTP/1.1\r\n"
            . "Host: 127.0.0.1:9000\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Protocol: ocpp1.6\r\n";

        if ($authorization !== null) {
            $httpRequest .= "Authorization: {$authorization}\r\n";
        }

        $httpRequest .= "\r\n";

        $command ??= app(OcppServe::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $reflection = new ReflectionClass($command);
        $clientsProperty = $reflection->getProperty('clients');
        $clientsProperty->setAccessible(true);

        $clientId = (int) $serverSocket;
        $existingClients = $clientsProperty->getValue($command);
        $existingClients[$clientId] = [
            'socket' => $serverSocket,
            'buffer' => $httpRequest,
            'handshaken' => false,
            'boot_received' => false,
            'station' => null,
            'last_command_poll_at' => 0,
        ];
        $clientsProperty->setValue($command, $existingClients);

        $performHandshake = $reflection->getMethod('performHandshake');
        $performHandshake->setAccessible(true);
        $performHandshake->invoke($command, $clientId);

        $clients = $clientsProperty->getValue($command);
        $handshaken = (bool) ($clients[$clientId]['handshaken'] ?? false);

        $response = '';
        while (! str_contains($response, "\r\n\r\n")) {
            $chunk = fread($clientSocket, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }

        fclose($clientSocket);
        if (is_resource($serverSocket)) {
            fclose($serverSocket);
        }

        return [$handshaken, $response];
    }
}
