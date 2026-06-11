<?php

namespace App\Console\Commands;

use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\OcppMessage;
use App\Models\Station;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ChargingStopService;
use App\Services\OcppMeterValuesParser;
use App\Services\OcppService;
use App\Services\SessionEnergyService;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class OcppServe extends Command
{
    protected $signature = 'ocpp:serve {--host=} {--port=}';

    protected $description = 'Run the Volta OCPP 1.6J WebSocket gateway.';

    private array $clients = [];

    /** @var array<string, float> */
    private array $lastTelemetryPollAt = [];

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

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);

            $shutdown = function () {
                $this->shutdownGateway();
                exit(self::SUCCESS);
            };

            pcntl_signal(SIGINT, $shutdown);
            pcntl_signal(SIGTERM, $shutdown);
        }

        register_shutdown_function(function () {
            $this->shutdownGateway();
        });

        while (true) {
            $this->acceptClient($server);
            $this->readClients($billingService);
            $this->scheduleActiveSessionTelemetry();
            $this->flushPendingCommands();
            usleep(50000);
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
            'boot_received' => false,
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

        $requestTarget = explode(' ', $requestLine)[1] ?? '';
        $path = parse_url($requestTarget, PHP_URL_PATH) ?: '/';
        $query = parse_url($requestTarget, PHP_URL_QUERY);
        $station = $this->resolveStationFromHandshake($path, is_string($query) ? $query : '', $headers);

        if (! isset($headers['sec-websocket-key'])) {
            $this->rejectHandshake($clientId, $requestLine, 'missing Sec-WebSocket-Key');

            return;
        }

        if (! $station) {
            $this->rejectHandshake($clientId, $requestLine, 'unknown charge point identity for path ' . $path);

            return;
        }

        $accept = base64_encode(sha1($headers['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $protocol = $this->negotiateSubprotocol($headers['sec-websocket-protocol'] ?? '');

        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n";

        if ($protocol !== null) {
            $response .= "Sec-WebSocket-Protocol: {$protocol}\r\n";
        }

        $response .= "\r\n";

        @fwrite($this->clients[$clientId]['socket'], $response);

        $station->update([
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_ocpp_message_at' => now(),
        ]);

        $this->clients[$clientId]['handshaken'] = true;
        $this->clients[$clientId]['station'] = $station->fresh();
        $this->clients[$clientId]['buffer'] = '';
        $this->supersedeStationClients($station->id, $clientId);
        $this->info("OCPP station connected: {$station->ocpp_identity} (path {$path})");
    }

    private function resolveStationFromHandshake(string $path, string $queryString, array $headers): ?Station
    {
        $identities = [];
        $normalizedPath = trim($path, '/');
        $segments = $normalizedPath !== '' ? explode('/', $normalizedPath) : [];

        if (count($segments) >= 2 && strtolower($segments[0]) === 'ocpp') {
            foreach (array_slice($segments, 1) as $segment) {
                $identities[] = rawurldecode($segment);
            }
        } elseif (count($segments) === 1 && strtolower($segments[0]) !== 'ocpp') {
            $identities[] = rawurldecode($segments[0]);
        }

        if ($queryString !== '') {
            parse_str($queryString, $params);
            foreach (['chargeBoxId', 'chargePointId', 'identity', 'id'] as $key) {
                if (! empty($params[$key])) {
                    $identities[] = rawurldecode((string) $params[$key]);
                }
            }
        }

        foreach (['chargeboxidentity', 'chargepointidentity', 'x-chargebox-id'] as $header) {
            if (! empty($headers[$header])) {
                $identities[] = rawurldecode((string) $headers[$header]);
            }
        }

        foreach (array_unique(array_filter($identities)) as $identity) {
            $station = Station::query()
                ->where('ocpp_identity', $identity)
                ->orWhere('qr_code', $identity)
                ->first();
            if ($station) {
                return $station;
            }
        }

        foreach (array_unique(array_filter($identities)) as $identity) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]/', '', $identity) ?? '');
            if (strlen($normalized) < 8) {
                continue;
            }

            $station = Station::query()->get()->first(function (Station $candidate) use ($normalized) {
                foreach ($candidate->scanTokens() as $token) {
                    $tokenNormalized = strtolower(preg_replace('/[^a-z0-9]/', '', $token) ?? '');
                    if ($tokenNormalized !== '' && $tokenNormalized === $normalized) {
                        return true;
                    }
                }

                return false;
            });

            if ($station) {
                return $station;
            }
        }

        $configuredStations = Station::query()
            ->whereNotNull('ocpp_identity')
            ->where('ocpp_identity', '!=', '')
            ->get();

        if ($configuredStations->count() === 1 && ($normalizedPath === '' || $normalizedPath === 'ocpp')) {
            return $configuredStations->first();
        }

        return null;
    }

    private function negotiateSubprotocol(string $requestedHeader): ?string
    {
        $requested = array_values(array_filter(array_map(
            static fn (string $value) => trim($value),
            explode(',', $requestedHeader)
        )));

        if ($requested === []) {
            return null;
        }

        foreach ($requested as $protocol) {
            if (strcasecmp($protocol, 'ocpp1.6') === 0) {
                return 'ocpp1.6';
            }
        }

        return null;
    }

    private function rejectHandshake(int $clientId, string $requestLine, string $reason): void
    {
        $this->warn("OCPP handshake rejected: {$reason} | {$requestLine}");
        @fwrite($this->clients[$clientId]['socket'], "HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n");
        $this->disconnectClient($clientId);
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

        if (! in_array($action, ['Heartbeat', 'MeterValues'], true)) {
            $this->info(sprintf(
                'OCPP inbound %s <- %s: %s',
                $station->ocpp_identity,
                $action,
                json_encode($payload, JSON_UNESCAPED_SLASHES)
            ));
        }

        try {
            $response = match ($action) {
                'BootNotification' => $this->onBootNotification($clientId, $station, $payload),
                'Heartbeat' => $this->onHeartbeat($station),
                'StatusNotification' => $this->onStatusNotification($station, $payload),
                'DiagnosticsStatusNotification' => $this->onDiagnosticsStatusNotification($station, $payload),
                'Authorize' => $this->onAuthorize($station, $payload),
                'StartTransaction' => $this->onStartTransaction($station, $payload),
                'MeterValues' => $this->onMeterValues($station, $payload),
                'StopTransaction' => $this->onStopTransaction($station, $payload, $billingService),
                'GetConfiguration' => $this->onGetConfiguration($payload),
                'ChangeConfiguration' => $this->onChangeConfiguration($payload),
                'DataTransfer' => $this->onDataTransfer($payload),
                default => null,
            };
        } catch (Throwable $exception) {
            Log::error('OCPP call handler failed', [
                'station_id' => $station->id,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);

            $response = match ($action) {
                'StartTransaction' => [
                    'transactionId' => 0,
                    'idTagInfo' => ['status' => 'Invalid'],
                ],
                'StopTransaction' => ['idTagInfo' => ['status' => 'Accepted']],
                default => null,
            };

            if ($response === null) {
                $this->sendCallError($clientId, $uid, 'InternalError', 'Handler failed for ' . $action);

                return;
            }
        }

        if ($response === null) {
            $this->sendCallError($clientId, $uid, 'NotImplemented', 'Action not supported: ' . $action);

            return;
        }

        $this->send($clientId, [3, $uid, $response]);
        $this->recordMessage($station, 'outbound', $uid, $action . 'Response', $response, 'sent');
    }

    private function onBootNotification(int $clientId, Station $station, array $payload): array
    {
        $serial = trim((string) ($payload['chargePointSerialNumber'] ?? $payload['chargeBoxSerialNumber'] ?? ''));
        $existing = is_array($station->ocpp_configuration) ? $station->ocpp_configuration : [];
        $configuration = array_merge($existing, array_filter([
            'chargePointVendor' => $payload['chargePointVendor'] ?? null,
            'chargePointModel' => $payload['chargePointModel'] ?? null,
            'chargePointSerialNumber' => $payload['chargePointSerialNumber'] ?? null,
            'chargeBoxSerialNumber' => $payload['chargeBoxSerialNumber'] ?? null,
            'firmwareVersion' => $payload['firmwareVersion'] ?? null,
        ]));

        $model = strtolower((string) ($configuration['chargePointModel'] ?? ''));
        if (str_contains($model, '1060') || str_contains($model, 'eu1060')) {
            $configuration['NumberOfConnectors'] = 2;
        }

        $connectors = is_array($configuration['connectors'] ?? null) ? $configuration['connectors'] : [];
        $connectorCount = max(1, (int) ($configuration['NumberOfConnectors'] ?? 1));
        for ($connectorId = 1; $connectorId <= $connectorCount; $connectorId++) {
            $connectors[$connectorId] = array_merge(
                ['connectorId' => $connectorId],
                $connectors[$connectorId] ?? []
            );
        }
        $configuration['connectors'] = $connectors;

        $updates = [
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_heartbeat_at' => now(),
            'last_ocpp_message_at' => now(),
            'ocpp_configuration' => $configuration,
        ];

        if ($serial !== '') {
            $currentQr = trim((string) ($station->qr_code ?? ''));
            if ($currentQr === '' || str_starts_with(strtolower($currentQr), 'station:')) {
                $updates['qr_code'] = $serial;
            }

            $currentIdentity = trim((string) ($station->ocpp_identity ?? ''));
            if ($currentIdentity === '' || str_starts_with(strtolower($currentIdentity), 'volta-')) {
                $updates['ocpp_identity'] = $serial;
            }
        }

        $station->update($updates);
        $this->clients[$clientId]['boot_received'] = true;
        app(OcppService::class)->pushMeterSampleIntervalConfig($station->fresh());
        $this->retryPendingRemoteStarts($station->fresh());

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

    private function onDiagnosticsStatusNotification(Station $station, array $payload): array
    {
        $uploadStatus = trim((string) ($payload['status'] ?? ''));
        if ($uploadStatus === '') {
            return [];
        }

        $command = OcppCommand::query()
            ->where('station_id', $station->id)
            ->where('action', 'GetDiagnostics')
            ->orderByDesc('id')
            ->first();

        if ($command) {
            $response = is_array($command->response_payload) ? $command->response_payload : [];
            $command->update([
                'response_payload' => array_merge($response, [
                    'upload_status' => $uploadStatus,
                    'upload_status_at' => now()->toIso8601String(),
                ]),
            ]);
        }

        return [];
    }

    private function onStatusNotification(Station $station, array $payload): array
    {
        $ocppStatus = (string) ($payload['status'] ?? '');
        $rawConnectorId = (int) ($payload['connectorId'] ?? 0);

        if ($rawConnectorId <= 0) {
            $station->update([
                'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
                'last_ocpp_message_at' => now(),
            ]);

            return [];
        }

        $connectorId = $rawConnectorId;
        $configuration = is_array($station->ocpp_configuration) ? $station->ocpp_configuration : [];
        $connectorState = array_filter([
            'connectorId' => $connectorId,
            'status' => $ocppStatus ?: null,
            'errorCode' => $payload['errorCode'] ?? null,
            'info' => $payload['info'] ?? null,
            'vendorId' => $payload['vendorId'] ?? null,
            'vendorErrorCode' => $payload['vendorErrorCode'] ?? null,
            'timestamp' => isset($payload['timestamp'])
                ? $this->parseOcppTime($payload['timestamp'])->toIso8601String()
                : now()->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== '');

        $connectors = is_array($configuration['connectors'] ?? null) ? $configuration['connectors'] : [];
        $connectors[$connectorId] = array_merge($connectors[$connectorId] ?? [], $connectorState);
        $configuration['connectors'] = $connectors;
        $configuration['connector'] = $connectors[$connectorId];

        $station->update([
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'status' => Station::aggregateStationStatus($connectors, $station->status ?: Station::STATUS_OFFLINE),
            'last_ocpp_message_at' => now(),
            'ocpp_configuration' => $configuration,
        ]);

        if (in_array($ocppStatus, Station::VEHICLE_CONNECTED_STATUSES, true)) {
            $this->activatePendingSessionForConnector($station, $connectorId);
        }

        if (in_array($ocppStatus, ['Charging', 'SuspendedEV', 'SuspendedEVSE'], true)) {
            unset($this->lastTelemetryPollAt[$station->id . ':' . $connectorId]);
            app(OcppService::class)->queueMeterValuesTrigger($station->fresh(), $connectorId, true);
        }

        if (in_array($ocppStatus, ['SuspendedEV', 'SuspendedEVSE'], true)) {
            $this->maybeRecoverSuspendedConnector($station->fresh(), $connectorId);
        }

        if (in_array($ocppStatus, ['Available', 'Finishing'], true)) {
            app(ChargingStopService::class)->maybeAutoFinalizeOnStationStatus(
                $station->fresh(),
                $connectorId,
                $ocppStatus
            );
        }

        return [];
    }

    private function onAuthorize(Station $station, array $payload): array
    {
        $idTag = (string) ($payload['idTag'] ?? '');
        $status = $this->isAcceptedIdTag($station, $idTag) ? 'Accepted' : 'Invalid';

        return ['idTagInfo' => ['status' => $status]];
    }

    private function onGetConfiguration(array $payload): array
    {
        $requestedKeys = is_array($payload['key'] ?? null) ? $payload['key'] : [];
        $known = [
            'HeartbeatInterval' => (string) config('services.ocpp.heartbeat_interval', 60),
            'NumberOfConnectors' => '2',
            'AuthorizeRemoteTxRequests' => 'false',
            'MeterValueSampleInterval' => (string) app(OcppService::class)->meterValueSampleIntervalSeconds(),
            'ConnectionTimeOut' => '60',
            'LocalAuthorizeOffline' => 'true',
            'LocalPreAuthorize' => 'false',
        ];

        $keys = $requestedKeys === []
            ? array_keys($known)
            : array_values(array_filter($requestedKeys, static fn ($key) => is_string($key) && $key !== ''));

        $configurationKey = [];
        $unknownKey = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $known)) {
                $configurationKey[] = [
                    'key' => $key,
                    'readonly' => true,
                    'value' => $known[$key],
                ];
                continue;
            }

            $unknownKey[] = $key;
        }

        return [
            'configurationKey' => $configurationKey,
            'unknownKey' => $unknownKey,
        ];
    }

    private function onChangeConfiguration(array $payload): array
    {
        $key = (string) ($payload['key'] ?? '');
        $writable = [
            'MeterValueSampleInterval',
            'HeartbeatInterval',
        ];

        if ($key === '' || ! in_array($key, $writable, true)) {
            return ['status' => 'Rejected'];
        }

        return ['status' => 'Accepted'];
    }

    private function onDataTransfer(array $payload): array
    {
        return ['status' => 'Accepted', 'data' => ''];
    }

    private function onStartTransaction(Station $station, array $payload): array
    {
        $idTag = (string) ($payload['idTag'] ?? '');
        $connectorId = max(1, (int) ($payload['connectorId'] ?? 1));
        $meterStart = $this->normalizeMeterStartFromOcpp($payload['meterStart'] ?? null);
        $startedAt = $this->parseOcppTime($payload['timestamp'] ?? null);
        $user = $this->userFromIdTag($idTag);
        $session = null;

        if (! $user) {
            $session = $this->findLinkableAppSession($station, $connectorId);
            $user = $session ? User::query()->find($session->user_id) : null;
        }

        if (! $user) {
            return ['transactionId' => 0, 'idTagInfo' => ['status' => 'Invalid']];
        }

        if (! $session) {
            $session = ChargingSession::query()
                ->where('station_id', $station->id)
                ->where('user_id', $user->id)
                ->whereNull('end_time')
                ->where(function ($query) use ($connectorId) {
                    $query->where('ocpp_connector_id', $connectorId)
                        ->orWhereNull('ocpp_connector_id');
                })
                ->latest('id')
                ->first();
        }

        if (! $session) {
            $session = ChargingSession::query()->create([
                'user_id' => $user->id,
                'station_id' => $station->id,
                'ocpp_connector_id' => $connectorId,
                'start_time' => $startedAt,
                'start_source' => 'ocpp',
                'ocpp_id_tag' => $idTag,
                'meter_start_kwh' => $meterStart,
                'kwh_consumed' => 0,
            ]);
        }

        $session->update([
            'ocpp_transaction_id' => (string) $session->id,
            'ocpp_connector_id' => $connectorId,
            'ocpp_id_tag' => $idTag,
            'meter_start_kwh' => $this->resolveMeterStartForSession($session, $meterStart),
            'start_time' => $session->start_time ?: $startedAt,
            'live_metrics' => array_merge(
                is_array($session->live_metrics) ? $session->live_metrics : [],
                [
                    'energy_integrated_kwh' => 0,
                    'assigned_transaction_id' => (int) $session->id,
                ]
            ),
        ]);

        $station->rememberLocalIdTag($connectorId, $idTag);

        $station->update([
            'status' => Station::STATUS_CHARGING,
            'meter_value_kwh' => $meterStart ?? $station->meter_value_kwh,
            'last_ocpp_message_at' => now(),
        ]);

        unset($this->lastTelemetryPollAt[$station->id . ':' . $connectorId]);
        app(OcppService::class)->queueMeterValuesTrigger($station->fresh(), $connectorId, true);

        $session = $session->fresh(['user']);
        if ($session->user) {
            app(\App\Services\PushNotificationService::class)->notifyChargingStarted(
                $session->user,
                $station->name
            );
        }

        return [
            'transactionId' => $session->id,
            'idTagInfo' => ['status' => 'Accepted'],
        ];
    }

    private function onMeterValues(Station $station, array $payload): array
    {
        $transactionId = (string) ($payload['transactionId'] ?? '');
        $connectorId = (int) ($payload['connectorId'] ?? 0);
        $metrics = app(OcppMeterValuesParser::class)->parse($payload['meterValue'] ?? []);
        $meterKwh = $metrics['energy_kwh'];

        $configuration = $station->ocpp_configuration ?? [];
        $liveMeter = array_filter([
            'power_kw' => $metrics['power_kw'],
            'current_a' => $metrics['current_a'],
            'voltage_v' => $metrics['voltage_v'],
            'soc_percent' => $metrics['soc_percent'],
            'temperature_c' => $metrics['temperature_c'],
            'energy_kwh' => $meterKwh,
            'sampled_at' => $metrics['sampled_at'] ?? now()->toIso8601String(),
        ], static fn ($value) => $value !== null && $value !== '');

        $configuration['live_meter'] = $liveMeter;

        if ($connectorId > 0) {
            $connectors = is_array($configuration['connectors'] ?? null) ? $configuration['connectors'] : [];
            $connectors[$connectorId] = array_merge($connectors[$connectorId] ?? [], [
                'connectorId' => $connectorId,
                'live_meter' => $liveMeter,
            ]);
            $configuration['connectors'] = $connectors;
        }

        $stationUpdate = [
            'last_ocpp_message_at' => now(),
            'ocpp_configuration' => $configuration,
        ];

        if ($meterKwh !== null) {
            $stationUpdate['meter_value_kwh'] = $meterKwh;
        }

        $station->update($stationUpdate);

        $session = $this->findSessionForTransaction($station, $transactionId, $connectorId);

        if ($session) {
            $session->rememberReportedTransactionId($transactionId);

            $energyService = app(SessionEnergyService::class);
            $previousLive = is_array($session->live_metrics) ? $session->live_metrics : [];
            $sessionUpdate = [
                'live_metrics' => array_filter([
                    'power_kw' => $metrics['power_kw'],
                    'current_a' => $metrics['current_a'],
                    'voltage_v' => $metrics['voltage_v'],
                    'soc_percent' => $metrics['soc_percent'],
                    'temperature_c' => $metrics['temperature_c'],
                    'previous_energy_kwh' => $previousLive['energy_kwh'] ?? null,
                    'energy_kwh' => $meterKwh,
                    'sampled_at' => $metrics['sampled_at'] ?? now()->toIso8601String(),
                    'measurands' => $metrics['measurands'],
                ], static fn ($value) => $value !== null && $value !== '' && $value !== []),
            ];

            $energyUpdate = $energyService->resolveDeliveredKwh($session, $meterKwh, $metrics);
            $sessionUpdate['kwh_consumed'] = $energyUpdate['kwh_consumed'];
            $sessionUpdate['live_metrics']['energy_integrated_kwh'] = $energyUpdate['energy_integrated_kwh'];

            if ($metrics['power_kw'] !== null) {
                $samples = is_array($previousLive['power_samples'] ?? null)
                    ? $previousLive['power_samples']
                    : [];
                $samples[] = [
                    't' => now()->timestamp,
                    'kw' => round((float) $metrics['power_kw'], 3),
                ];
                if (count($samples) > 120) {
                    $samples = array_slice($samples, -120);
                }
                $sessionUpdate['live_metrics']['power_samples'] = $samples;
            }

            if (isset($energyUpdate['meter_start_kwh'])) {
                $sessionUpdate['meter_start_kwh'] = $energyUpdate['meter_start_kwh'];
            }

            $session->update($sessionUpdate);

            app(WalletService::class)->maybeAutoStopForBudget($session->fresh(), $station);
        }

        if ($metrics['energy_kwh'] !== null || $metrics['power_kw'] !== null || $metrics['current_a'] !== null) {
            $delivered = '—';
            if ($session) {
                $freshSession = $session->fresh();
                $delivered = SessionEnergyService::usesSessionRelativeRegister($freshSession)
                    ? (string) ($metrics['energy_kwh'] ?? $freshSession->kwh_consumed ?? '—')
                    : (string) ($freshSession->kwh_consumed ?? '—');
            }

            $this->info(sprintf(
                'OCPP MeterValues %s connector %d: register=%s kWh delivered=%s kW=%s A=%s V=%s',
                $station->ocpp_identity,
                $connectorId,
                $metrics['energy_kwh'] ?? '—',
                $delivered,
                $metrics['power_kw'] ?? '—',
                $metrics['current_a'] ?? '—',
                $metrics['voltage_v'] ?? '—',
            ));
        }

        return [];
    }

    private function onStopTransaction(Station $station, array $payload, BillingService $billingService): array
    {
        $transactionId = (string) ($payload['transactionId'] ?? '');
        $connectorId = (int) ($payload['connectorId'] ?? 0);
        $meterStop = $this->wattHoursToKwh($payload['meterStop'] ?? null);
        $endedAt = $this->parseOcppTime($payload['timestamp'] ?? null);
        $reason = (string) ($payload['reason'] ?? '');
        $session = $this->findSessionForTransaction($station, $transactionId, $connectorId);

        if (
            $session
            && ! $session->end_time
            && ! $session->ocpp_transaction_id
            && ($transactionId === '' || $transactionId === '0')
            && in_array($reason, ['SoftReset', 'HardReset', 'Reboot'], true)
        ) {
            $station->update(['last_ocpp_message_at' => now()]);

            return ['idTagInfo' => ['status' => 'Accepted']];
        }

        if ($session && ! $session->end_time) {
            $stopSource = $this->resolveStopSourceForSession($session, $reason);

            app(ChargingStopService::class)->finalizeStop(
                $session,
                $station->fresh(),
                $stopSource,
                $endedAt,
                $meterStop
            );
        }

        $stopIdTag = strtoupper(trim((string) ($payload['idTag'] ?? '')));
        if ($stopIdTag !== '') {
            $station->rememberLocalIdTag(
                (int) ($session?->ocpp_connector_id ?: ($connectorId > 0 ? $connectorId : 1)),
                $stopIdTag
            );
        }

        $station->update([
            'meter_value_kwh' => $meterStop ?? $station->meter_value_kwh,
            'last_ocpp_message_at' => now(),
        ]);
        $station->markConnectorAvailable((int) ($session?->ocpp_connector_id ?: 1));

        return ['idTagInfo' => ['status' => 'Accepted']];
    }

    private function handleCallResult(Station $station, array $message): void
    {
        $uid = (string) ($message[1] ?? '');
        $payload = is_array($message[2] ?? null) ? $message[2] : [];
        $command = OcppCommand::query()->where('message_uid', $uid)->first();
        $status = $this->commandResultStatus($command, $payload);

        if ($command) {
            $command->update([
                'status' => $status,
                'response_payload' => $payload,
                'acknowledged_at' => now(),
            ]);

            if ($command->action === 'RemoteStartTransaction' && $status === OcppCommand::STATUS_ACCEPTED) {
                $connectorId = (int) ($command->payload['connectorId'] ?? 0);
                if ($connectorId > 0) {
                    $station->updateConnectorOcppStatus($connectorId, 'Preparing');
                }
            }

            if (
                $command->action === 'RemoteStartTransaction'
                && $status === OcppCommand::STATUS_REJECTED
            ) {
                $connectorId = (int) ($command->payload['connectorId'] ?? 0);
                $session = $command->chargingSession?->fresh()
                    ?? $this->findPendingAppSession(
                        $station,
                        $connectorId > 0 ? $connectorId : null
                    );

                $this->dispatchConnectorRecovery(
                    $station->fresh(),
                    $connectorId > 0 ? $connectorId : (int) ($session?->ocpp_connector_id ?? 1),
                    $session,
                    'remote_start_rejected',
                    true
                );
            }

            if (
                in_array($command->action, ['RemoteStopTransaction', 'RequestStopTransaction'], true)
                && $status === OcppCommand::STATUS_REJECTED
            ) {
                $session = $command->chargingSession?->fresh();

                if ($session && ! $session->end_time) {
                    $this->queueSoftResetIfNeeded($station, (int) ($session->ocpp_connector_id ?: 1));
                }
            }
        }

        $this->info(sprintf(
            'OCPP result %s <- %s: %s',
            $station->ocpp_identity,
            $command?->action ?? 'unknown',
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        ));

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

    private function scheduleActiveSessionTelemetry(): void
    {
        if (config('services.ocpp.mode', 'gateway') === 'simulator') {
            return;
        }

        $intervalSeconds = app(OcppService::class)->meterValueSampleIntervalSeconds();
        $ocppService = app(OcppService::class);
        $now = microtime(true);

        foreach ($this->clients as $client) {
            $station = $client['station'] ?? null;
            if (! $client['handshaken'] || ! $station instanceof Station) {
                continue;
            }

            $station = $station->fresh();
            $connectorIds = $station->activelyDeliveringConnectorIds();

            foreach (
                ChargingSession::query()
                    ->where('station_id', $station->id)
                    ->whereNull('end_time')
                    ->get() as $session
            ) {
                $connectorIds[] = max(1, (int) ($session->ocpp_connector_id ?: 1));
            }

            $connectorIds = array_values(array_unique(array_filter($connectorIds)));

            foreach ($connectorIds as $connectorId) {
                $key = $station->id . ':' . $connectorId;

                if (($this->lastTelemetryPollAt[$key] ?? 0) + $intervalSeconds > $now) {
                    continue;
                }

                $command = $ocppService->queueMeterValuesTrigger($station, $connectorId, true);
                if ($command === null) {
                    continue;
                }

                $this->lastTelemetryPollAt[$key] = $now;
            }
        }
    }

    private function commandPollIntervalSeconds(): float
    {
        return max(0.05, (int) config('services.ocpp.command_poll_interval_ms', 250)) / 1000;
    }

    private function commandBatchSize(): int
    {
        return max(1, (int) config('services.ocpp.command_batch_size', 6));
    }

    private function flushPendingCommands(): void
    {
        $pollInterval = $this->commandPollIntervalSeconds();
        $batchSize = $this->commandBatchSize();

        foreach ($this->clients as $id => $client) {
            /** @var Station|null $station */
            $station = $client['station'];

            if (
                ! $client['handshaken']
                || ! ($client['boot_received'] ?? false)
                || ! $station
                || microtime(true) - $client['last_command_poll_at'] < $pollInterval
            ) {
                continue;
            }

            $this->clients[$id]['last_command_poll_at'] = microtime(true);
            $commands = OcppCommand::query()
                ->where('station_id', $station->id)
                ->readyToSend()
                ->orderByDispatchPriority()
                ->limit($batchSize)
                ->get();

            foreach ($commands as $command) {
                $payload = $command->payload ?? [];
                $this->info(sprintf(
                    'OCPP outbound %s -> %s: %s',
                    $station->ocpp_identity,
                    $command->action,
                    json_encode($payload, JSON_UNESCAPED_SLASHES)
                ));
                $this->send($id, [2, $command->message_uid, $command->action, $payload]);
                $command->update([
                    'status' => OcppCommand::STATUS_SENT,
                    'sent_at' => now(),
                ]);
                $this->recordMessage($station, 'outbound', $command->message_uid, $command->action, $command->payload ?? [], OcppCommand::STATUS_SENT);
            }
        }
    }

    private function supersedeStationClients(int $stationId, int $exceptClientId): void
    {
        foreach ($this->clients as $id => $client) {
            if ($id === $exceptClientId || ! $client['handshaken']) {
                continue;
            }

            $station = $client['station'] ?? null;
            if (! $station instanceof Station || $station->id !== $stationId) {
                continue;
            }

            @fclose($client['socket']);
            unset($this->clients[$id]);
            $this->info("OCPP superseded stale socket for {$station->ocpp_identity}");
        }
    }

    private function hasActiveClientForStation(int $stationId): bool
    {
        foreach ($this->clients as $client) {
            if (
                $client['handshaken']
                && $client['station'] instanceof Station
                && $client['station']->id === $stationId
            ) {
                return true;
            }
        }

        return false;
    }

    private function disconnectClient(int $clientId): void
    {
        $station = $this->clients[$clientId]['station'] ?? null;
        $stationId = $station instanceof Station ? $station->id : null;

        @fclose($this->clients[$clientId]['socket']);
        unset($this->clients[$clientId]);

        if ($stationId && ! $this->hasActiveClientForStation($stationId)) {
            Station::query()
                ->whereKey($stationId)
                ->update([
                    'ocpp_connection_status' => Station::OCPP_CONNECTION_DISCONNECTED,
                    'status' => Station::STATUS_OFFLINE,
                ]);
            $this->info("OCPP station disconnected: {$station->ocpp_identity}");
        }
    }

    private function shutdownGateway(): void
    {
        static $shutdownHandled = false;

        if ($shutdownHandled) {
            return;
        }

        $shutdownHandled = true;

        foreach (array_keys($this->clients) as $clientId) {
            $this->disconnectClient((int) $clientId);
        }
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

        if (preg_match('/^VOLTA(\d+)$/', $idTag, $matches)) {
            return User::query()->find((int) $matches[1]);
        }

        return User::query()->where('email', $idTag)->first();
    }

    private function isAcceptedIdTag(Station $station, string $idTag): bool
    {
        if ($idTag === '' || $this->userFromIdTag($idTag)) {
            return $idTag !== '';
        }

        if ($this->findLinkableAppSession($station)) {
            return true;
        }

        $configuration = is_array($station->ocpp_configuration) ? $station->ocpp_configuration : [];
        $tags = is_array($configuration['local_id_tags'] ?? null) ? $configuration['local_id_tags'] : [];

        return in_array(strtoupper($idTag), array_map('strtoupper', $tags), true);
    }

    private function findPendingAppSession(Station $station, ?int $connectorId = null): ?ChargingSession
    {
        $query = ChargingSession::query()
            ->where('station_id', $station->id)
            ->whereNull('end_time')
            ->whereNull('ocpp_transaction_id');

        if ($connectorId && $connectorId > 0) {
            $byConnector = (clone $query)
                ->where('ocpp_connector_id', $connectorId)
                ->latest('id')
                ->first();

            if ($byConnector) {
                return $byConnector;
            }
        }

        return $query->latest('id')->first();
    }

    /**
     * App session waiting for StartTransaction.conf, including when MeterValues arrived first
     * and pre-assigned ocpp_transaction_id to the session primary key.
     */
    private function findLinkableAppSession(Station $station, ?int $connectorId = null): ?ChargingSession
    {
        $query = ChargingSession::query()
            ->where('station_id', $station->id)
            ->whereNull('end_time')
            ->where(function ($query): void {
                $query->whereNull('ocpp_transaction_id')
                    ->orWhereColumn('ocpp_transaction_id', 'id');
            });

        if ($connectorId && $connectorId > 0) {
            $byConnector = (clone $query)
                ->where('ocpp_connector_id', $connectorId)
                ->latest('id')
                ->first();

            if ($byConnector) {
                return $byConnector;
            }
        }

        return $query->latest('id')->first();
    }

    private function normalizeMeterStartFromOcpp(mixed $meterStart): ?float
    {
        return SessionEnergyService::effectiveMeterStart($this->wattHoursToKwh($meterStart));
    }

    private function resolveMeterStartForSession(ChargingSession $session, ?float $meterStartFromOcpp): ?float
    {
        $fromOcpp = SessionEnergyService::effectiveMeterStart($meterStartFromOcpp);
        $existing = SessionEnergyService::effectiveMeterStart($session->meter_start_kwh);

        return $fromOcpp ?? $existing;
    }

    private function activatePendingSessionForConnector(Station $station, int $connectorId): void
    {
        $session = $this->findPendingAppSession($station, $connectorId);

        if (! $session) {
            return;
        }

        $session->update([
            'ocpp_connector_id' => $connectorId,
        ]);

        $recentAcceptedStart = OcppCommand::query()
            ->where('charging_session_id', $session->id)
            ->where('action', 'RemoteStartTransaction')
            ->where('status', OcppCommand::STATUS_ACCEPTED)
            ->where('acknowledged_at', '>', now()->subSeconds(45))
            ->exists();

        if ($recentAcceptedStart) {
            return;
        }

        app(OcppService::class)->requeueRemoteStartForSession($station->fresh(), $session->fresh(), 2);
    }

    private function retryPendingRemoteStarts(Station $station): void
    {
        $sessions = ChargingSession::query()
            ->where('station_id', $station->id)
            ->whereNull('end_time')
            ->whereNull('ocpp_transaction_id')
            ->latest('id')
            ->get();

        foreach ($sessions as $session) {
            app(OcppService::class)->requeueRemoteStartForSession($station, $session, 1);
        }
    }

    private function findSessionForTransaction(Station $station, string $transactionId, int $connectorId = 0): ?ChargingSession
    {
        if ($transactionId === '' || $transactionId === '0') {
            if ($connectorId > 0) {
                $byConnector = ChargingSession::query()
                    ->where('station_id', $station->id)
                    ->whereNull('end_time')
                    ->where('ocpp_connector_id', $connectorId)
                    ->latest('id')
                    ->first();

                if ($byConnector) {
                    return $byConnector;
                }
            }

            $pending = $this->findLinkableAppSession(
                $station,
                $connectorId > 0 ? $connectorId : null
            );

            if ($pending) {
                return $pending;
            }

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

    private function commandResultStatus(?OcppCommand $command, array $payload): string
    {
        $raw = (string) ($payload['status'] ?? 'Accepted');

        if ($raw === 'Accepted') {
            return OcppCommand::STATUS_ACCEPTED;
        }

        if ($command?->action === 'ChangeAvailability' && $raw === 'Scheduled') {
            return OcppCommand::STATUS_ACCEPTED;
        }

        return OcppCommand::STATUS_REJECTED;
    }

    private function sendCallError(int $clientId, string $uid, string $errorCode, string $description): void
    {
        $this->send($clientId, [4, $uid, $errorCode, $description, []]);
    }

    private function resolveStopSourceForSession(ChargingSession $session, string $reason): string
    {
        $appRequested = OcppCommand::query()
            ->where('charging_session_id', $session->id)
            ->whereIn('action', ['RemoteStopTransaction', 'RequestStopTransaction'])
            ->whereIn('status', [
                OcppCommand::STATUS_PENDING,
                OcppCommand::STATUS_SENT,
                OcppCommand::STATUS_ACCEPTED,
            ])
            ->exists();

        if ($appRequested || in_array($reason, ['Remote', 'SoftReset', 'HardReset'], true)) {
            return 'app';
        }

        return 'ocpp';
    }

    private function queueSoftResetIfNeeded(Station $station, int $connectorId): void
    {
        if (! config('services.ocpp.auto_recovery_enabled', false)) {
            return;
        }

        if (! config('services.ocpp.soft_reset_on_stop_reject', true)) {
            return;
        }

        if (app(OcppService::class)->hasRecentConnectorRecovery($station->id, $connectorId, 90)) {
            return;
        }

        app(OcppService::class)->queueOutboundCommand($station, 'Reset', [
            'type' => 'Soft',
        ]);

        $this->info(sprintf(
            'OCPP queued Soft Reset for %s after RemoteStop rejected on connector %d',
            $station->ocpp_identity,
            $connectorId
        ));
    }

    private function maybeRecoverSuspendedConnector(Station $station, int $connectorId): void
    {
        if (! config('services.ocpp.auto_recovery_enabled', false)) {
            return;
        }

        if (! config('services.ocpp.soft_reset_on_suspended_ev', true)) {
            return;
        }

        $session = $this->findPendingAppSession($station, $connectorId);

        if (! $session || $session->ocpp_transaction_id) {
            return;
        }

        $waitSeconds = (int) config('services.ocpp.suspended_ev_recovery_seconds', 12);

        $waitingSince = $session->start_time;
        $lastAcceptedStart = OcppCommand::query()
            ->where('charging_session_id', $session->id)
            ->where('action', 'RemoteStartTransaction')
            ->where('status', OcppCommand::STATUS_ACCEPTED)
            ->whereNotNull('acknowledged_at')
            ->latest('acknowledged_at')
            ->value('acknowledged_at');

        if ($lastAcceptedStart && (! $waitingSince || $lastAcceptedStart > $waitingSince)) {
            $waitingSince = $lastAcceptedStart;
        }

        if (! $waitingSince || $waitingSince->greaterThan(now()->subSeconds($waitSeconds))) {
            return;
        }

        if ($station->connectorOcppStatus($connectorId) === 'Charging') {
            return;
        }

        $hasInFlightStart = OcppCommand::query()
            ->where('charging_session_id', $session->id)
            ->where('action', 'RemoteStartTransaction')
            ->whereIn('status', [
                OcppCommand::STATUS_PENDING,
                OcppCommand::STATUS_SENT,
            ])
            ->exists();

        if ($hasInFlightStart) {
            return;
        }

        $this->dispatchConnectorRecovery(
            $station,
            $connectorId,
            $session,
            'suspended_ev',
            false
        );
    }

    private function dispatchConnectorRecovery(
        Station $station,
        int $connectorId,
        ?ChargingSession $session,
        string $reason,
        bool $force
    ): void {
        if ($connectorId <= 0) {
            return;
        }

        if (! config('services.ocpp.auto_recovery_enabled', false)) {
            return;
        }

        if ($session?->end_time || $session?->ocpp_transaction_id) {
            $session = null;
        }

        $commandIds = app(OcppService::class)->recoverConnectorForRemoteStart(
            $station,
            $connectorId,
            $session,
            $reason,
            $force
        );

        if ($commandIds !== []) {
            $this->info(sprintf(
                'OCPP recovery queued for %s connector %d (%s, session %s)',
                $station->ocpp_identity,
                $connectorId,
                $reason,
                $session?->id ?? 'none'
            ));

            return;
        }

        $this->warn(sprintf(
            'OCPP recovery skipped for %s connector %d (%s, cooldown or disconnected)',
            $station->ocpp_identity,
            $connectorId,
            $reason
        ));
    }
}
