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

class OcppSupersedeAfterBootTest extends TestCase
{
    use RefreshDatabase;

    public function test_handshake_does_not_supersede_existing_socket_before_boot_notification(): void
    {
        $station = Station::query()->create([
            'name' => 'Statia V CHARGE 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => '5D419400481F59D750010067',
            'qr_code' => '5D419400481F59D750010067',
        ]);

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        [$firstSocket, $firstPeer] = $sockets;
        fclose($firstPeer);

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        [$secondSocket, $secondPeer] = $sockets;
        fclose($secondPeer);

        $command = app(OcppServe::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $reflection = new ReflectionClass($command);
        $clientsProperty = $reflection->getProperty('clients');
        $clientsProperty->setAccessible(true);

        $firstClientId = (int) $firstSocket;
        $secondClientId = (int) $secondSocket;

        $clientsProperty->setValue($command, [
            $firstClientId => $this->clientState($firstSocket, $station, true),
            $secondClientId => $this->clientState($secondSocket, null, false),
        ]);

        $performHandshake = $reflection->getMethod('performHandshake');
        $performHandshake->setAccessible(true);

        $key = base64_encode(random_bytes(16));
        $clients = $clientsProperty->getValue($command);
        $clients[$secondClientId]['buffer'] = "GET /ocpp/{$station->ocpp_identity} HTTP/1.1\r\n"
            . "Host: 127.0.0.1:9000\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Protocol: ocpp1.6\r\n"
            . "\r\n";
        $clientsProperty->setValue($command, $clients);

        $performHandshake->invoke($command, $secondClientId);

        $clients = $clientsProperty->getValue($command);
        $this->assertArrayHasKey($firstClientId, $clients, 'Existing socket must survive handshake of a parallel connection');
        $this->assertArrayHasKey($secondClientId, $clients);
        $this->assertTrue($clients[$secondClientId]['handshaken']);

        fclose($firstSocket);
        fclose($secondSocket);
    }

    public function test_finalize_boot_notification_supersedes_other_station_sockets(): void
    {
        $station = Station::query()->create([
            'name' => 'Statia V CHARGE 1',
            'location' => 'Depou',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => '5D419400481F59D750010067',
            'qr_code' => '5D419400481F59D750010067',
        ]);

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        [$staleSocket, $stalePeer] = $sockets;
        fclose($stalePeer);

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        [$activeSocket, $activePeer] = $sockets;
        fclose($activePeer);

        $command = app(OcppServe::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $reflection = new ReflectionClass($command);
        $clientsProperty = $reflection->getProperty('clients');
        $clientsProperty->setAccessible(true);

        $staleClientId = (int) $staleSocket;
        $activeClientId = (int) $activeSocket;

        $clientsProperty->setValue($command, [
            $staleClientId => $this->clientState($staleSocket, $station, true),
            $activeClientId => $this->clientState($activeSocket, $station, true),
        ]);

        $finalizeBootNotification = $reflection->getMethod('finalizeBootNotification');
        $finalizeBootNotification->setAccessible(true);
        $finalizeBootNotification->invoke($command, $activeClientId, $station, [
            'chargePointVendor' => 'VENDOR',
            'chargePointModel' => 'EU1060_TYPE_II',
            'chargePointSerialNumber' => '5D419400481F59D750010067',
            'firmwareVersion' => 'ACM4_EVSE_V12.27',
        ]);

        $clients = $clientsProperty->getValue($command);
        $this->assertArrayNotHasKey($staleClientId, $clients);
        $this->assertArrayHasKey($activeClientId, $clients);
        $this->assertTrue($clients[$activeClientId]['boot_received']);

        fclose($activeSocket);
    }

    /**
     * @param  resource  $socket
     * @return array<string, mixed>
     */
    private function clientState($socket, ?Station $station, bool $handshaken): array
    {
        return [
            'socket' => $socket,
            'buffer' => '',
            'handshaken' => $handshaken,
            'boot_received' => false,
            'station' => $station,
            'last_command_poll_at' => 0,
            'message_fragment' => '',
            'fragment_opcode' => null,
            'close_code' => null,
        ];
    }
}
