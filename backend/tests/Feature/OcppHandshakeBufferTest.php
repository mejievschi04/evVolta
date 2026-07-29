<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use App\Models\Station;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class OcppHandshakeBufferTest extends TestCase
{
    use RefreshDatabase;

    public function test_handshake_preserves_boot_notification_sent_in_same_tcp_packet(): void
    {
        $station = Station::query()->create([
            'name' => 'Statia V CHARGE 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => '5D419400481F59D750010067',
            'qr_code' => '5D419400481F59D750010067',
        ]);

        $bootMessage = json_encode([
            2,
            'boot-uid-1',
            'BootNotification',
            [
                'chargePointVendor' => 'VENDOR',
                'chargePointModel' => 'EU1060_TYPE_II',
                'chargePointSerialNumber' => '5D419400481F59D750010067',
                'firmwareVersion' => 'ACM4_EVSE_V12.27',
            ],
        ], JSON_UNESCAPED_SLASHES);

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
            . "Sec-WebSocket-Protocol: ocpp1.6\r\n"
            . "\r\n";

        $combined = $httpRequest . $this->encodeClientTextFrame($bootMessage);

        $command = app(OcppServe::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $reflection = new ReflectionClass($command);
        $clientsProperty = $reflection->getProperty('clients');
        $clientsProperty->setAccessible(true);

        $clientId = (int) $serverSocket;
        $clientsProperty->setValue($command, [
            $clientId => [
                'socket' => $serverSocket,
                'buffer' => $combined,
                'handshaken' => false,
                'boot_received' => false,
                'station' => null,
                'last_command_poll_at' => 0,
                'message_fragment' => '',
                'fragment_opcode' => null,
                'close_code' => null,
            ],
        ]);

        $performHandshake = $reflection->getMethod('performHandshake');
        $performHandshake->setAccessible(true);
        $performHandshake->invoke($command, $clientId);

        $clients = $clientsProperty->getValue($command);
        $this->assertTrue($clients[$clientId]['handshaken']);
        $this->assertNotSame('', $clients[$clientId]['buffer'], 'Post-handshake WebSocket bytes must be preserved');

        $decodeFrames = $reflection->getMethod('decodeFrames');
        $decodeFrames->setAccessible(true);
        $buffer = $clients[$clientId]['buffer'];
        $decoded = $decodeFrames->invokeArgs($command, [&$buffer]);

        $this->assertCount(1, $decoded);
        $this->assertSame($bootMessage, $decoded[0]);

        $response = '';
        while (! str_contains($response, "\r\n\r\n")) {
            $chunk = fread($clientSocket, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }

        $this->assertStringContainsString('101 Switching Protocols', $response);
        $this->assertStringContainsString('Sec-WebSocket-Protocol: ocpp1.6', $response);

        fclose($serverSocket);
        fclose($clientSocket);
    }

    private function encodeClientTextFrame(string $payload): string
    {
        $length = strlen($payload);
        $frame = chr(0x81);
        $maskKey = random_bytes(4);

        if ($length <= 125) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('NN', 0, $length);
        }

        $frame .= $maskKey;

        for ($i = 0; $i < $length; $i++) {
            $frame .= $payload[$i] ^ $maskKey[$i % 4];
        }

        return $frame;
    }
}
