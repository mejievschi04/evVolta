<?php

namespace Tests\Feature;

use App\Console\Commands\OcppServe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class OcppFragmentedFrameTest extends TestCase
{
    use RefreshDatabase;

    public function test_decode_frames_reassembles_fragmented_boot_notification(): void
    {
        $bootMessage = json_encode([
            2,
            'boot-frag',
            'BootNotification',
            ['chargePointSerialNumber' => '5D419400481F59D750010067'],
        ], JSON_UNESCAPED_SLASHES);

        $firstPart = substr($bootMessage, 0, 40);
        $secondPart = substr($bootMessage, 40);

        $command = app(OcppServe::class);
        $reflection = new ReflectionClass($command);
        $clientsProperty = $reflection->getProperty('clients');
        $clientsProperty->setAccessible(true);
        $clientsProperty->setValue($command, [
            1 => [
                'socket' => null,
                'message_fragment' => '',
                'fragment_opcode' => null,
            ],
        ]);

        $decodeFrames = $reflection->getMethod('decodeFrames');
        $decodeFrames->setAccessible(true);

        $buffer = $this->encodeMaskedTextFrame($firstPart, false)
            . $this->encodeMaskedTextFrame($secondPart, true, true);
        $decoded = $decodeFrames->invokeArgs($command, [&$buffer, 1]);

        $this->assertCount(1, $decoded['messages']);
        $this->assertSame($bootMessage, $decoded['messages'][0]);
    }

    private function encodeMaskedTextFrame(string $payload, bool $fin, bool $continuation = false): string
    {
        $length = strlen($payload);
        $opcode = $continuation ? 0 : 1;
        $frame = chr(($fin ? 0x80 : 0x00) | $opcode);
        $maskKey = random_bytes(4);

        if ($length <= 125) {
            $frame .= chr(0x80 | $length);
        } else {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        }

        $frame .= $maskKey;

        for ($i = 0; $i < $length; $i++) {
            $frame .= $payload[$i] ^ $maskKey[$i % 4];
        }

        return $frame;
    }
}
