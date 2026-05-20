<?php

namespace App\Console\Commands;

use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\OcppMessage;
use App\Models\Station;
use App\Models\User;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class OcppServe extends Command
{
    protected $signature = 'ocpp:serve {--host=} {--port=}';

    protected $description = 'Run the Volta OCPP 1.6J WebSocket gateway.';

    private array $clients = [];

    public function handle(BillingService $billingService): int
    {
        $host = $this->option('host') ?: config('services.ocpp.host', '0.0.0.0');
        $port = (int) ($this->option('port') ?: config('services.ocpp.port', 9000));
        $server = @stream_socket_server("tcp://{$host}:{$port}", $errorCode, $errorMessage);

        if (! $server) {
            $this->error("OCPP gateway failed to start: {$errorMessage} ({$errorCode})");

            return self::FAILURE;
        }

        stream_set_blocking($server, false);
        $this->info("Volta OCPP gateway listening on ws://{$host}:{$port}/ocpp/{ocpp_identity}");

        while (true) {
            $this->acceptClient($server);
            $this->readClients($billingService);
            $this->flushPendingCommands();
            usleep(100000);
        }
    }

    private function acceptClient($server): void
    {
        $socket = @stream_socket_accept($server, 0);

        if (! $socket) {
            return;
        }

        stream_set_blocking($socket, false);
        $id = (int) $socket;
        $this->clients[$id] = [
            'socket' => $socket,
            'buffer' => '',
            'handshaken' => false,
            'station' => null,
            'last_command_poll_at' => 0,
        ];
    }

    private function readClients(BillingService $billingService): void
    {
        foreach ($this->clients as $id => $client) {
            $chunk = @fread($client['socket'], 8192);

            if ($chunk === '' || $chunk === false) {
                if (feof($client['socket'])) {
                    $this->disconnectClient($id);
                }

                continue;
            }

            $this->clients[$id]['buffer'] .= $chunk;

            if (! $this->clients[$id]['handshaken']) {
                $this->performHandshake($id);
                continue;
            }

            foreach ($this->decodeFrames($this->clients[$id]['buffer']) as $message) {
                $this->handleOcppMessage($id, $message, $billingService);
            }
        }
    }

    private function performHandshake(int $clientId): void
    {
        $buffer = $this->clients[$clientId]['buffer'];

        if (! str_contains($buffer, "\r\n\r\n")) {
            return;
        }

        [$headerBlock] = explode("\r\n\r\n", $buffer, 2);
        $lines = explode("\r\n", $headerBlock);
        $requestLine = array_shift($lines) ?: '';
        $headers = [];

        foreach ($lines as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        $path = parse_url(explode(' ', $requestLine)[1] ?? '', PHP_URL_PATH) ?: '';
        $identity = rawurldecode(basename($path));
        $station = Station::query()->where('ocpp_identity', $identity)->first();

        if (! $station || ! isset($headers['sec-websocket-key'])) {
            @fwrite($this->clients[$clientId]['socket'], "HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n");
            $this->disconnectClient($clientId);

            return;
        }

        $accept = base64_encode(sha1($headers['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n"
            . "Sec-WebSocket-Protocol: ocpp1.6\r\n\r\n";

        @fwrite($this->clients[$clientId]['socket'], $response);

        $station->update([
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_ocpp_message_at' => now(),
        ]);

        $this->clients[$clientId]['handshaken'] = true;
        $this->clients[$clientId]['station'] = $station->fresh();
        $this->clients[$clientId]['buffer'] = '';
        $this->info("OCPP station connected: {$identity}");
    }

    private function handleOcppMessage(int $clientId, string $rawMessage, BillingService $billingService): void
    {
        $station = $this->clients[$clientId]['station'];
        $message = json_decode($rawMessage, true);

        if (! is_array($message) || ! $station) {
            return;
        }

        try {
            $messageType = $message[0] ?? null;

            if ($messageType === 2) {
                $this->handleCall($clientId, $station, $message, $billingService);
                return;
            }

            if ($messageType === 3) {
                $this->handleCallResult($station, $message);
                return;
            }

            if ($messageType === 4) {
                $this->handleCallError($station, $message);
            }
        } catch (Throwable $exception) {
            Log::error('OCPP message handling failed', [
                'station_id' => $station->id,
                'message' => $rawMessage,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function handleCall(int $clientId, Station $station, array $message, BillingService $billingService): void
    {
        $uid = (string) ($message[1] ?? '');
        $action = (string) ($message[2] ?? '');
        $payload = is_array($message[3] ?? null) ? $message[3] : [];

        $this->recordMessage($station, 'inbound', $uid, $action, $payload);
        $station->update(['last_ocpp_message_at' => now()]);

        $response = match ($action) {
            'BootNotification' => $this->onBootNotification($station, $payload),
            'Heartbeat' => $this->onHeartbeat($station),
            'StatusNotification' => $this->onStatusNotification($station, $payload),
            'Authorize' => $this->onAuthorize($payload),
            'StartTransaction' => $this->onStartTransaction($station, $payload),
            'MeterValues' => $this->onMeterValues($station, $payload),
            'StopTransaction' => $this->onStopTransaction($station, $payload, $billingService),
            default => [],
        };

        $this->send($clientId, [3, $uid, $response]);
        $this->recordMessage($station, 'outbound', $uid, $action . 'Response', $response, 'sent');
    }

    private function onBootNotification(Station $station, array $payload): array
    {
        $station->update([
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'last_ocpp_message_at' => now(),
            'ocpp_configuration' => array_filter([
                'chargePointVendor' => $payload['chargePointVendor'] ?? null,
                'chargePointModel' => $payload['chargePointModel'] ?? null,
                'chargePointSerialNumber' => $payload['chargePointSerialNumber'] ?? null,
                'firmwareVersion' => $payload['firmwareVersion'] ?? null,
            ]),
        ]);

        return [
            'currentTime' => now()->toIso8601ZuluString(),
            'interval' => (int) config('services.ocpp.heartbeat_interval', 60),
            'status' => 'Accepted',
        ];
    }

    private function onHeartbeat(Station $station): array
    {
        $station->update([
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'last_ocpp_message_at' => now(),
        ]);

        return ['currentTime' => now()->toIso8601ZuluString()];
    }

    private function onStatusNotification(Station $station, array $payload): array
    {
        $ocppStatus = (string) ($payload['status'] ?? '');
        $configuration = $station->ocpp_configuration ?? [];
        $configuration['connector'] = array_filter([
            'connectorId' => $payload['connectorId'] ?? 1,
            'status' => $ocppStatus ?: null,
            'errorCode' => $payload['errorCode'] ?? null,
            'info' => $payload['info'] ?? null,
            'vendorId' => $payload['vendorId'] ?? null,
            'vendorErrorCode' => $payload['vendorErrorCode'] ?? null,
            'timestamp' => isset($payload['timestamp'])
                ? $this->parseOcppTime($payload['timestamp'])->toIso8601String()
                : now()->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== '');

        $station->update([
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'status' => match ($ocppStatus) {
                'Available' => Station::STATUS_AVAILABLE,
                'Charging', 'Preparing', 'SuspendedEV', 'SuspendedEVSE', 'Finishing' => Station::STATUS_CHARGING,
                default => Station::STATUS_OFFLINE,
            },
            'last_ocpp_message_at' => now(),
            'ocpp_configuration' => $configuration,
        ]);

        return [];
    }

    private function onAuthorize(array $payload): array
    {
        $idTag = (string) ($payload['idTag'] ?? '');
        $status = $this->userFromIdTag($idTag) || $idTag !== '' ? 'Accepted' : 'Invalid';

        return ['idTagInfo' => ['status' => $status]];
    }

    private function onStartTransaction(Station $station, array $payload): array
    {
        $idTag = (string) ($payload['idTag'] ?? '');
        $user = $this->userFromIdTag($idTag);
        $meterStart = $this->wattHoursToKwh($payload['meterStart'] ?? null);
        $startedAt = $this->parseOcppTime($payload['timestamp'] ?? null);

        if (! $user) {
            return ['transactionId' => 0, 'idTagInfo' => ['status' => 'Invalid']];
        }

        $session = ChargingSession::query()
            ->where('station_id', $station->id)
            ->where('user_id', $user->id)
            ->whereNull('end_time')
            ->latest('id')
            ->first();

        if (! $session) {
            $session = ChargingSession::query()->create([
                'user_id' => $user->id,
                'station_id' => $station->id,
                'start_time' => $startedAt,
                'start_source' => 'ocpp',
                'ocpp_id_tag' => $idTag,
                'meter_start_kwh' => $meterStart,
                'kwh_consumed' => 0,
            ]);
        }

        $session->update([
            'ocpp_transaction_id' => (string) $session->id,
            'ocpp_id_tag' => $idTag,
            'meter_start_kwh' => $meterStart ?? $session->meter_start_kwh,
            'start_time' => $session->start_time ?: $startedAt,
        ]);

        $station->update([
            'status' => Station::STATUS_CHARGING,
            'meter_value_kwh' => $meterStart ?? $station->meter_value_kwh,
            'last_ocpp_message_at' => now(),
        ]);

        return [
            'transactionId' => $session->id,
            'idTagInfo' => ['status' => 'Accepted'],
        ];
    }

    private function onMeterValues(Station $station, array $payload): array
    {
        $transactionId = (string) ($payload['transactionId'] ?? '');
        $meterKwh = $this->latestMeterKwh($payload['meterValue'] ?? []);

        if ($meterKwh !== null) {
            $station->update([
                'meter_value_kwh' => $meterKwh,
                'last_ocpp_message_at' => now(),
            ]);
        }

        $session = $this->findSessionForTransaction($station, $transactionId);

        if ($session && $meterKwh !== null) {
            $meterStart = $session->meter_start_kwh ?? $meterKwh;
            $session->update([
                'meter_start_kwh' => $meterStart,
                'kwh_consumed' => round(max(0, $meterKwh - $meterStart), 3),
            ]);
        }

        return [];
    }

    private function onStopTransaction(Station $station, array $payload, BillingService $billingService): array
    {
        $transactionId = (string) ($payload['transactionId'] ?? '');
        $meterStop = $this->wattHoursToKwh($payload['meterStop'] ?? null);
        $endedAt = $this->parseOcppTime($payload['timestamp'] ?? null);
        $session = $this->findSessionForTransaction($station, $transactionId);

        if ($session) {
            $meterStart = $session->meter_start_kwh ?? $meterStop ?? 0;
            $kwh = $meterStop !== null ? round(max(0, $meterStop - $meterStart), 3) : $session->kwh_consumed;

            $session->update([
                'end_time' => $endedAt,
                'meter_stop_kwh' => $meterStop,
                'kwh_consumed' => $kwh,
                'stop_source' => 'ocpp',
            ]);

            $billingService->createSessionInvoice($session->fresh());
        }

        $station->update([
            'status' => Station::STATUS_AVAILABLE,
            'meter_value_kwh' => $meterStop ?? $station->meter_value_kwh,
            'last_ocpp_message_at' => now(),
        ]);

        return ['idTagInfo' => ['status' => 'Accepted']];
    }

    private function handleCallResult(Station $station, array $message): void
    {
        $uid = (string) ($message[1] ?? '');
        $payload = is_array($message[2] ?? null) ? $message[2] : [];
        $command = OcppCommand::query()->where('message_uid', $uid)->first();
        $status = ($payload['status'] ?? 'Accepted') === 'Accepted'
            ? OcppCommand::STATUS_ACCEPTED
            : OcppCommand::STATUS_REJECTED;

        if ($command) {
            $command->update([
                'status' => $status,
                'response_payload' => $payload,
                'acknowledged_at' => now(),
            ]);
        }

        $this->recordMessage($station, 'inbound', $uid, $command?->action . 'Result', $payload, $status);
    }

    private function handleCallError(Station $station, array $message): void
    {
        $uid = (string) ($message[1] ?? '');
        $errorCode = (string) ($message[2] ?? 'GenericError');
        $description = (string) ($message[3] ?? '');
        $command = OcppCommand::query()->where('message_uid', $uid)->first();

        if ($command) {
            $command->update([
                'status' => OcppCommand::STATUS_FAILED,
                'error_message' => $errorCode . ': ' . $description,
                'acknowledged_at' => now(),
            ]);
        }

        $this->recordMessage($station, 'inbound', $uid, $command?->action . 'Error', [
            'errorCode' => $errorCode,
            'description' => $description,
            'details' => $message[4] ?? null,
        ], OcppCommand::STATUS_FAILED, $errorCode, $description);
    }

    private function flushPendingCommands(): void
    {
        foreach ($this->clients as $id => $client) {
            /** @var Station|null $station */
            $station = $client['station'];

            if (! $client['handshaken'] || ! $station || microtime(true) - $client['last_command_poll_at'] < 1) {
                continue;
            }

            $this->clients[$id]['last_command_poll_at'] = microtime(true);
            $commands = OcppCommand::query()
                ->where('station_id', $station->id)
                ->where('status', OcppCommand::STATUS_PENDING)
                ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->orderBy('id')
                ->limit(5)
                ->get();

            foreach ($commands as $command) {
                $this->send($id, [2, $command->message_uid, $command->action, $command->payload ?? []]);
                $command->update([
                    'status' => OcppCommand::STATUS_SENT,
                    'sent_at' => now(),
                ]);
                $this->recordMessage($station, 'outbound', $command->message_uid, $command->action, $command->payload ?? [], OcppCommand::STATUS_SENT);
            }
        }
    }

    private function disconnectClient(int $clientId): void
    {
        $station = $this->clients[$clientId]['station'] ?? null;

        if ($station instanceof Station) {
            $station->update(['ocpp_connection_status' => Station::OCPP_CONNECTION_DISCONNECTED]);
            $this->info("OCPP station disconnected: {$station->ocpp_identity}");
        }

        @fclose($this->clients[$clientId]['socket']);
        unset($this->clients[$clientId]);
    }

    private function send(int $clientId, array $message): void
    {
        @fwrite($this->clients[$clientId]['socket'], $this->encodeFrame(json_encode($message, JSON_UNESCAPED_SLASHES)));
    }

    private function recordMessage(
        Station $station,
        string $direction,
        ?string $uid,
        ?string $action,
        ?array $payload,
        string $status = 'received',
        ?string $errorCode = null,
        ?string $errorDescription = null
    ): void {
        OcppMessage::query()->create([
            'station_id' => $station->id,
            'direction' => $direction,
            'message_uid' => $uid,
            'action' => $action,
            'status' => $status,
            'error_code' => $errorCode,
            'error_description' => $errorDescription,
            'payload' => $payload,
            'received_at' => now(),
        ]);
    }

    private function userFromIdTag(string $idTag): ?User
    {
        if (preg_match('/^user:(\d+)$/', $idTag, $matches)) {
            return User::query()->find((int) $matches[1]);
        }

        return User::query()->where('email', $idTag)->first();
    }

    private function findSessionForTransaction(Station $station, string $transactionId): ?ChargingSession
    {
        if ($transactionId === '') {
            return ChargingSession::query()
                ->where('station_id', $station->id)
                ->whereNull('end_time')
                ->latest('id')
                ->first();
        }

        return ChargingSession::query()
            ->where('station_id', $station->id)
            ->where(function ($query) use ($transactionId): void {
                $query->where('ocpp_transaction_id', $transactionId);

                if (is_numeric($transactionId)) {
                    $query->orWhere('id', (int) $transactionId);
                }
            })
            ->latest('id')
            ->first();
    }

    private function latestMeterKwh(array $meterValues): ?float
    {
        $latest = null;

        foreach ($meterValues as $meterValue) {
            foreach (($meterValue['sampledValue'] ?? []) as $sample) {
                $measurand = $sample['measurand'] ?? 'Energy.Active.Import.Register';

                if ($measurand !== 'Energy.Active.Import.Register') {
                    continue;
                }

                $value = isset($sample['value']) ? (float) $sample['value'] : null;
                $unit = strtolower((string) ($sample['unit'] ?? 'Wh'));
                $latest = $unit === 'kwh' ? $value : $value / 1000;
            }
        }

        return $latest;
    }

    private function wattHoursToKwh(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round(((float) $value) / 1000, 3);
    }

    private function parseOcppTime(mixed $value): Carbon
    {
        if (! is_string($value) || $value === '') {
            return now();
        }

        return Carbon::parse($value);
    }

    private function decodeFrames(string &$buffer): array
    {
        $messages = [];

        while (strlen($buffer) >= 2) {
            $bytes = unpack('Cfirst/Csecond', substr($buffer, 0, 2));
            $payloadLength = $bytes['second'] & 127;
            $offset = 2;

            if ($payloadLength === 126) {
                if (strlen($buffer) < 4) {
                    break;
                }
                $payloadLength = unpack('n', substr($buffer, 2, 2))[1];
                $offset = 4;
            } elseif ($payloadLength === 127) {
                if (strlen($buffer) < 10) {
                    break;
                }
                $parts = unpack('N2', substr($buffer, 2, 8));
                $payloadLength = ($parts[1] << 32) + $parts[2];
                $offset = 10;
            }

            $masked = ($bytes['second'] & 128) === 128;
            $mask = '';

            if ($masked) {
                if (strlen($buffer) < $offset + 4) {
                    break;
                }
                $mask = substr($buffer, $offset, 4);
                $offset += 4;
            }

            if (strlen($buffer) < $offset + $payloadLength) {
                break;
            }

            $payload = substr($buffer, $offset, $payloadLength);
            $buffer = substr($buffer, $offset + $payloadLength);

            if ($masked) {
                $decoded = '';
                for ($i = 0; $i < $payloadLength; $i++) {
                    $decoded .= $payload[$i] ^ $mask[$i % 4];
                }
                $payload = $decoded;
            }

            $messages[] = $payload;
        }

        return $messages;
    }

    private function encodeFrame(string $payload): string
    {
        $length = strlen($payload);

        if ($length <= 125) {
            return chr(129) . chr($length) . $payload;
        }

        if ($length <= 65535) {
            return chr(129) . chr(126) . pack('n', $length) . $payload;
        }

        return chr(129) . chr(127) . pack('NN', 0, $length) . $payload;
    }
}
