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

class OcppWebSocketFramingTest extends TestCase
{
    use RefreshDatabase;

    private OcppServe $gateway;

    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = app(OcppServe::class);
        $this->gateway->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $this->reflection = new ReflectionClass($this->gateway);
    }

    public function test_ping_is_answered_with_pong_carrying_same_payload(): void
    {
        [$clientId, $peer] = $this->connectedClient();

        $this->writeToServer($peer, $this->clientFrame('keepalive', 0x9));
        $this->invoke('readClients', app(BillingService::class));

        $frames = $this->readServerFrames($peer);
        $this->assertCount(1, $frames);
        $this->assertSame(0xA, $frames[0]['opcode']);
        $this->assertSame('keepalive', $frames[0]['payload']);
        $this->assertArrayHasKey($clientId, $this->clients());
    }

    public function test_close_frame_is_echoed_and_client_disconnected(): void
    {
        [$clientId, $peer, $station] = $this->connectedClient();

        $this->writeToServer($peer, $this->clientFrame(pack('n', 1000), 0x8));
        $this->invoke('readClients', app(BillingService::class));

        $frames = $this->readServerFrames($peer);
        $this->assertCount(1, $frames);
        $this->assertSame(0x8, $frames[0]['opcode']);
        $this->assertSame(1000, unpack('n', $frames[0]['payload'])[1]);
        $this->assertArrayNotHasKey($clientId, $this->clients());
        $this->assertSame(Station::OCPP_CONNECTION_DISCONNECTED, $station->fresh()->ocpp_connection_status);
    }

    public function test_frames_after_close_are_ignored(): void
    {
        [$clientId, $peer] = $this->connectedClient();

        $buffer = $this->clientFrame(pack('n', 1000), 0x8) . $this->clientFrame('[2,"x","Heartbeat",{}]');
        $this->setClientKey($clientId, 'buffer', '');

        $decoded = $this->decode($buffer, $clientId);

        $this->assertSame([], $decoded);
        $this->assertSame(1000, $this->clients()[$clientId]['close_code']);
    }

    public function test_boot_notification_in_handshake_packet_is_answered_immediately(): void
    {
        $station = $this->station();
        [$server, $peer] = $this->socketPair();
        $clientId = (int) $server;
        $this->setClients([$clientId => $this->clientState($server, null, false)]);

        $boot = json_encode([2, 'boot-1', 'BootNotification', [
            'chargePointVendor' => 'VENDOR',
            'chargePointModel' => 'EU1060_TYPE_II',
        ]], JSON_UNESCAPED_SLASHES);

        $key = base64_encode(random_bytes(16));
        $this->writeToServer($peer, "GET /ocpp/{$station->ocpp_identity} HTTP/1.1\r\n"
            . "Host: 127.0.0.1:9000\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Protocol: ocpp1.6\r\n"
            . "\r\n"
            . $this->clientFrame($boot));

        $this->invoke('readClients', app(BillingService::class));

        $raw = $this->readAll($peer);
        $this->assertStringContainsString('101 Switching Protocols', $raw);

        $frames = $this->parseServerFrames(substr($raw, strpos($raw, "\r\n\r\n") + 4));
        $this->assertNotEmpty($frames, 'BootNotification must be answered without waiting for more data');
        $response = json_decode($frames[0]['payload'], true);
        $this->assertSame(3, $response[0]);
        $this->assertSame('boot-1', $response[1]);
        $this->assertSame('Accepted', $response[2]['status']);
    }

    public function test_partial_writes_are_buffered_and_delivered_in_order(): void
    {
        [$clientId, $peer] = $this->connectedClient();

        $big = str_repeat('a', 300_000);
        $this->assertTrue($this->invoke('send', $clientId, [3, 'big-1', ['blob' => $big]]));
        $this->assertTrue($this->invoke('send', $clientId, [3, 'small-2', []]));
        $this->assertNotSame('', $this->clients()[$clientId]['outbox'], 'Socket buffer should not take 300 KB at once');

        $received = '';
        for ($i = 0; $i < 200 && ($this->clients()[$clientId]['outbox'] ?? '') !== ''; $i++) {
            $received .= $this->readAll($peer);
            $this->invoke('flushOutboxes');
        }
        $received .= $this->readAll($peer);

        $frames = $this->parseServerFrames($received);
        $this->assertCount(2, $frames);
        $this->assertSame('big-1', json_decode($frames[0]['payload'], true)[1]);
        $this->assertSame($big, json_decode($frames[0]['payload'], true)[2]['blob']);
        $this->assertSame('small-2', json_decode($frames[1]['payload'], true)[1]);
    }

    public function test_oversized_frame_closes_with_1009(): void
    {
        [$clientId, $peer] = $this->connectedClient();

        // Header announcing 2 MB payload; body never needs to arrive.
        $header = chr(0x81) . chr(0x80 | 127) . pack('NN', 0, 2 * 1024 * 1024) . random_bytes(4);
        $buffer = $header;
        $this->assertSame([], $this->decode($buffer, $clientId));

        $this->assertSame(1009, $this->clients()[$clientId]['close_code']);
        $frames = $this->readServerFrames($peer);
        $this->assertSame(0x8, $frames[0]['opcode']);
        $this->assertSame(1009, unpack('n', $frames[0]['payload'])[1]);
    }

    public function test_continuation_without_start_is_protocol_error(): void
    {
        [$clientId, $peer] = $this->connectedClient();

        $buffer = $this->clientFrame('orphan', 0x0);
        $this->assertSame([], $this->decode($buffer, $clientId));
        $this->assertSame(1002, $this->clients()[$clientId]['close_code']);
        $this->assertSame(1002, unpack('n', $this->readServerFrames($peer)[0]['payload'])[1]);
    }

    public function test_ping_between_fragments_keeps_message_intact(): void
    {
        [$clientId, $peer] = $this->connectedClient();

        $message = '[2,"hb-1","Heartbeat",{}]';
        $buffer = $this->clientFrame(substr($message, 0, 10), 0x1, false)
            . $this->clientFrame('p', 0x9)
            . $this->clientFrame(substr($message, 10), 0x0);

        $this->assertSame([$message], $this->decode($buffer, $clientId));
        $frames = $this->readServerFrames($peer);
        $this->assertSame(0xA, $frames[0]['opcode']);
    }

    // --- helpers -----------------------------------------------------------

    private function connectedClient(): array
    {
        $station = $this->station();
        $station->update(['ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED]);
        [$server, $peer] = $this->socketPair();
        $clientId = (int) $server;
        $this->setClients([$clientId => $this->clientState($server, $station->fresh(), true)]);

        return [$clientId, $peer, $station];
    }

    private function station(): Station
    {
        return Station::query()->create([
            'name' => 'Statia WS',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => 'WS-' . bin2hex(random_bytes(4)),
            'qr_code' => 'WS-' . bin2hex(random_bytes(4)),
        ]);
    }

    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        return $pair;
    }

    private function clientState($socket, ?Station $station, bool $handshaken): array
    {
        return [
            'socket' => $socket,
            'buffer' => '',
            'handshaken' => $handshaken,
            'boot_received' => $handshaken,
            'station' => $station,
            'last_command_poll_at' => 0,
            'message_fragment' => '',
            'fragment_opcode' => null,
            'close_code' => null,
            'closing_at' => null,
            'outbox' => '',
        ];
    }

    private function clients(): array
    {
        $property = $this->reflection->getProperty('clients');
        $property->setAccessible(true);

        return $property->getValue($this->gateway);
    }

    private function setClients(array $clients): void
    {
        $property = $this->reflection->getProperty('clients');
        $property->setAccessible(true);
        $property->setValue($this->gateway, $clients);
    }

    private function setClientKey(int $clientId, string $key, mixed $value): void
    {
        $clients = $this->clients();
        $clients[$clientId][$key] = $value;
        $this->setClients($clients);
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $reflectionMethod = $this->reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invoke($this->gateway, ...$args);
    }

    private function decode(string &$buffer, int $clientId): array
    {
        $method = $this->reflection->getMethod('decodeFrames');
        $method->setAccessible(true);

        return $method->invokeArgs($this->gateway, [&$buffer, $clientId]);
    }

    private function writeToServer($peer, string $bytes): void
    {
        stream_set_blocking($peer, true);
        fwrite($peer, $bytes);
        stream_set_blocking($peer, false);
    }

    private function readAll($peer): string
    {
        $data = '';
        while (($chunk = fread($peer, 65536)) !== false && $chunk !== '') {
            $data .= $chunk;
        }

        return $data;
    }

    private function readServerFrames($peer): array
    {
        return $this->parseServerFrames($this->readAll($peer));
    }

    /**
     * @return list<array{opcode: int, payload: string}>
     */
    private function parseServerFrames(string $data): array
    {
        $frames = [];

        while (strlen($data) >= 2) {
            $opcode = ord($data[0]) & 0x0F;
            $length = ord($data[1]) & 0x7F;
            $offset = 2;

            if ($length === 126) {
                $length = unpack('n', substr($data, 2, 2))[1];
                $offset = 4;
            } elseif ($length === 127) {
                $length = unpack('N2', substr($data, 2, 8))[2];
                $offset = 10;
            }

            if (strlen($data) < $offset + $length) {
                break;
            }

            $frames[] = ['opcode' => $opcode, 'payload' => substr($data, $offset, $length)];
            $data = substr($data, $offset + $length);
        }

        return $frames;
    }

    private function clientFrame(string $payload, int $opcode = 0x1, bool $fin = true): string
    {
        $length = strlen($payload);
        $frame = chr(($fin ? 0x80 : 0x00) | $opcode);
        $mask = random_bytes(4);

        if ($length <= 125) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('NN', 0, $length);
        }

        $frame .= $mask;

        for ($i = 0; $i < $length; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        return $frame;
    }
}
