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
use App\Services\OcppSessionDebugService;
use App\Services\OcppService;
use App\Services\SessionEnergyService;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class OcppServe extends Command
{
    protected $signature = 'ocpp:serve {--host=} {--port=}';

    protected $description = 'Run the V Charge OCPP 1.6J WebSocket gateway.';

    private array $clients = [];

    /** @var array<string, float> */
    private array $lastTelemetryPollAt = [];

    /** @var array<string, list<float>> station identity => handshake timestamps (unix) */
    private array $handshakeAttempts = [];

    private float $lastCommandExpiryAt = 0;

    private float $lastReleasedSweepAt = 0;

    private float $lastSuspendedSweepAt = 0;

    private float $lastUnstartedSweepAt = 0;

    /** Limita pentru un mesaj OCPP (inclusiv reasamblat din fragmente). */
    private const MAX_MESSAGE_BYTES = 1048576;

    /** Limita pentru date netrimise inca catre o statie (socket blocat). */
    private const MAX_OUTBOX_BYTES = 4194304;

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
        $this->info("V Charge OCPP gateway listening on ws://{$host}:{$port}/ocpp/{ocpp_identity}");

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
            $this->expireUnansweredCommands();
            $this->flushOutboxes();
            $this->stopSuspendedEvSessions();
            $this->finalizeReleasedSessions();
            $this->closeUnstartedSessions();
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
            'message_fragment' => '',
            'fragment_opcode' => null,
            'close_code' => null,
            'closing_at' => null,
            'outbox' => '',
        ];
    }

    private function readClients(BillingService $billingService): void
    {
        foreach (array_keys($this->clients) as $id) {
            // Clientul poate fi inchis intre timp (supersede / close) in aceeasi iteratie.
            if (! isset($this->clients[$id])) {
                continue;
            }

            if (! is_resource($this->clients[$id]['socket'])) {
                $this->disconnectClient($id);
                continue;
            }

            $socket = $this->clients[$id]['socket'];
            $chunk = @fread($socket, 8192);

            if ($chunk === '' || $chunk === false) {
                if (feof($socket)) {
                    $this->disconnectClient($id);
                }

                continue;
            }

            $this->clients[$id]['buffer'] .= $chunk;

            if (! $this->clients[$id]['handshaken']) {
                $this->performHandshake($id);

                // Handshake respins sau incomplet. Daca a reusit, procesam imediat
                // cadrele venite in acelasi pachet TCP (ex. BootNotification).
                if (! isset($this->clients[$id]) || ! $this->clients[$id]['handshaken']) {
                    continue;
                }
            }

            foreach ($this->decodeFrames($this->clients[$id]['buffer'], $id) as $message) {
                $this->handleOcppMessage($id, $message, $billingService);

                if (! isset($this->clients[$id])) {
                    break;
                }
            }

            $this->disconnectIfClosing($id);
        }
    }

    private function performHandshake(int $clientId): void
    {
        $buffer = $this->clients[$clientId]['buffer'];

        if (! str_contains($buffer, "\r\n\r\n")) {
            return;
        }

        [$headerBlock, $remainder] = array_pad(explode("\r\n\r\n", $buffer, 2), 2, '');
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

        if (! $this->stationAuthAccepted($station, $headers)) {
            $this->rejectHandshake($clientId, $requestLine, 'invalid OCPP station credentials', 401);

            return;
        }

        if (! $this->stationHandshakeRateAccepted($station)) {
            $this->rejectHandshake($clientId, $requestLine, 'handshake rate limit exceeded for '.$station->ocpp_identity, 429);

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

        $this->queueRaw($clientId, $response);

        $station->update([
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
            'last_ocpp_message_at' => now(),
        ]);

        $this->clients[$clientId]['handshaken'] = true;
        $this->clients[$clientId]['station'] = $station->fresh();
        $this->clients[$clientId]['buffer'] = $remainder;
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

    private function rejectHandshake(int $clientId, string $requestLine, string $reason, int $status = 404): void
    {
        $this->warn("OCPP handshake rejected: {$reason} | {$requestLine}");
        $statusLine = match ($status) {
            401 => 'HTTP/1.1 401 Unauthorized',
            429 => 'HTTP/1.1 429 Too Many Requests',
            default => 'HTTP/1.1 404 Not Found',
        };
        $extra = $status === 401 ? "WWW-Authenticate: Basic realm=\"ocpp\"\r\n" : '';
        if ($status === 429) {
            $extra .= "Retry-After: 60\r\n";
        }
        @fwrite(
            $this->clients[$clientId]['socket'],
            "{$statusLine}\r\n{$extra}Connection: close\r\n\r\n"
        );
        $this->disconnectClient($clientId);
    }

    /**
     * Optional per-station Basic Auth (OCPP Security Profile 1).
     * When ocpp_auth_password is empty, connections are accepted by identity only (legacy rollout).
     */
    private function stationAuthAccepted(Station $station, array $headers): bool
    {
        $hash = (string) ($station->ocpp_auth_password ?? '');
        if ($hash === '') {
            return true;
        }

        $authorization = (string) ($headers['authorization'] ?? '');
        if (! str_starts_with(strtolower($authorization), 'basic ')) {
            return false;
        }

        $decoded = base64_decode(trim(substr($authorization, 6)), true);
        if ($decoded === false || ! str_contains($decoded, ':')) {
            return false;
        }

        [$username, $password] = explode(':', $decoded, 2);
        $identity = (string) ($station->ocpp_identity ?? '');
        $usernameOk = $username === '' || ($identity !== '' && strcasecmp($username, $identity) === 0);

        return $usernameOk && Hash::check($password, $hash);
    }

    private function stationHandshakeRateAccepted(Station $station): bool
    {
        $limit = max(1, (int) config('services.ocpp.handshake_rate_limit', 30));
        $window = max(1, (int) config('services.ocpp.handshake_rate_window_seconds', 60));
        $key = (string) ($station->ocpp_identity ?: $station->id);
        $now = microtime(true);
        $cutoff = $now - $window;

        $attempts = array_values(array_filter(
            $this->handshakeAttempts[$key] ?? [],
            static fn (float $ts): bool => $ts >= $cutoff
        ));

        if (count($attempts) >= $limit) {
            $this->handshakeAttempts[$key] = $attempts;

            return false;
        }

        $attempts[] = $now;
        $this->handshakeAttempts[$key] = $attempts;

        return true;
    }

    private function handleOcppMessage(int $clientId, string $rawMessage, BillingService $billingService): void
    {
        $station = $this->clients[$clientId]['station'];
        $message = json_decode($rawMessage, true);

        if (! is_array($message) || ! $station) {
            return;
        }

        // Copia din memorie poate fi veche (API-ul, backoffice-ul si comenzile scriu in
        // acelasi rand). Fiecare mesaj lucreaza pe starea curenta din baza de date.
        try {
            $fresh = $station->fresh();
            if ($fresh) {
                $station = $fresh;
                $this->clients[$clientId]['station'] = $fresh;
            }
        } catch (Throwable $exception) {
            Log::warning('OCPP station refresh failed', [
                'station_id' => $station->id,
                'error' => $exception->getMessage(),
            ]);
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

        $station->updateOcppConfigurationAtomically(function (array $existing, Station $locked) use ($payload, $serial): array {
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
                $currentQr = trim((string) ($locked->qr_code ?? ''));
                if ($currentQr === '' || str_starts_with(strtolower($currentQr), 'station:')) {
                    $updates['qr_code'] = $serial;
                }

                $currentIdentity = trim((string) ($locked->ocpp_identity ?? ''));
                if ($currentIdentity === '' || str_starts_with(strtolower($currentIdentity), 'volta-')) {
                    $updates['ocpp_identity'] = $serial;
                }
            }

            return $updates;
        });
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
        $errorCode = (string) ($payload['errorCode'] ?? 'NoError');
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

        if ($errorCode !== '' && $errorCode !== 'NoError') {
            $connectorState['last_fault_at'] = now()->toIso8601String();
            $connectorState['last_fault_code'] = $errorCode;
            if (in_array($errorCode, OcppCommand::NON_AUTO_RECOVERY_ERROR_CODES, true)) {
                \Illuminate\Support\Facades\Log::warning('ocpp.connector.fault', [
                    'station_id' => $station->id,
                    'ocpp_identity' => $station->ocpp_identity,
                    'connector_id' => $connectorId,
                    'status' => $ocppStatus,
                    'error_code' => $errorCode,
                    'info' => $payload['info'] ?? null,
                    'vendor_error_code' => $payload['vendorErrorCode'] ?? null,
                ]);
            }
        }

        $station->updateOcppConfigurationAtomically(function (array $configuration, Station $locked) use ($connectorId, $connectorState, $ocppStatus): array {
            $connectors = is_array($configuration['connectors'] ?? null) ? $configuration['connectors'] : [];
            $previousConnector = $connectors[$connectorId] ?? [];
            $connectors[$connectorId] = array_merge($previousConnector, $connectorState);

            // Retinem cand conectorul a fost eliberat prima data (ora serverului), ca sa asteptam
            // StopTransaction cu contorul final inainte de inchiderea automata a sesiunii.
            $releasedStatuses = ['Available', 'Finishing'];
            if (in_array($ocppStatus, $releasedStatuses, true)) {
                $wasReleased = in_array($previousConnector['status'] ?? null, $releasedStatuses, true)
                    && ! empty($previousConnector['released_at']);
                if (! $wasReleased) {
                    $connectors[$connectorId]['released_at'] = now()->toIso8601String();
                }
            } else {
                unset($connectors[$connectorId]['released_at']);
            }

            if ($ocppStatus === 'SuspendedEV') {
                $connectors[$connectorId]['suspended_ev_since'] =
                    ($previousConnector['status'] ?? null) === 'SuspendedEV'
                        ? ($previousConnector['suspended_ev_since'] ?? now()->toIso8601String())
                        : now()->toIso8601String();
            } else {
                unset($connectors[$connectorId]['suspended_ev_since']);
            }

            $configuration['connectors'] = $connectors;
            $configuration['connector'] = $connectors[$connectorId];

            return [
                'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
                'status' => Station::aggregateStationStatus($connectors, $locked->status ?: Station::STATUS_OFFLINE),
                'last_ocpp_message_at' => now(),
                'ocpp_configuration' => $configuration,
            ];
        });

        if (in_array($ocppStatus, Station::VEHICLE_CONNECTED_STATUSES, true)) {
            $this->activatePendingSessionForConnector($station, $connectorId);
        }

        if (in_array($ocppStatus, ['Charging', 'SuspendedEV', 'SuspendedEVSE'], true)) {
            unset($this->lastTelemetryPollAt[$station->id . ':' . $connectorId]);
            app(OcppService::class)->queueMeterValuesTrigger($station->fresh(), $connectorId, true);
        }

        $blockingFault = in_array($errorCode, OcppCommand::NON_AUTO_RECOVERY_ERROR_CODES, true);

        if (! $blockingFault && in_array($ocppStatus, ['SuspendedEV', 'SuspendedEVSE'], true)) {
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
        Log::info('OCPP DataTransfer', [
            'vendor_id' => $payload['vendorId'] ?? null,
            'message_id' => $payload['messageId'] ?? null,
            'data_bytes' => is_string($payload['data'] ?? null)
                ? strlen((string) $payload['data'])
                : (is_array($payload['data'] ?? null) ? count($payload['data']) : null),
        ]);

        return ['status' => 'Accepted', 'data' => ''];
    }

    private function onStartTransaction(Station $station, array $payload): array
    {
        $idTag = (string) ($payload['idTag'] ?? '');
        $rawConnectorId = (int) ($payload['connectorId'] ?? 0);
        if ($rawConnectorId <= 0 && $station->expectedConnectorCount() > 1) {
            return ['transactionId' => 0, 'idTagInfo' => ['status' => 'Invalid']];
        }

        $connectorId = max(1, $rawConnectorId);
        if (ChargingSession::query()->where('station_id', $station->id)
            ->where('ocpp_connector_id', $connectorId)->whereNull('end_time')
            ->where('stop_source', ChargingSession::STOP_SOURCE_SUSPENDED_EV)->exists()) {
            return ['transactionId' => 0, 'idTagInfo' => ['status' => 'Invalid']];
        }
        $meterStart = $this->normalizeMeterStartFromOcpp($payload['meterStart'] ?? null);
        $startedAt = $this->parseOcppTime($payload['timestamp'] ?? null);
        $user = $this->userFromIdTag($idTag);
        // Prefer the app session that queued RemoteStart — local charger idTags have no user identity
        // and must not attach to another user's still-open Finishing row on the same connector.
        $session = $this->findSessionFromRecentRemoteStart($station, $connectorId);

        if ($session && $this->isProtectedFinishingSession($station, $session)) {
            $session = null;
        }

        if ($session && $user && (int) $session->user_id !== (int) $user->id) {
            // Never reassign a session to a different user — keep the starter.
            $user = User::query()->find($session->user_id);
        }

        if (! $session && ! $user) {
            $session = $this->findPendingAppSession($station, $connectorId)
                ?? $this->findMeterPreassignedAppSession($station, $connectorId);
            if ($session && $this->isProtectedFinishingSession($station, $session)) {
                $session = null;
            }
            $user = $session ? User::query()->find($session->user_id) : null;
        }

        if (! $user && $session) {
            $user = User::query()->find($session->user_id);
        }

        if (! $user) {
            return ['transactionId' => 0, 'idTagInfo' => ['status' => 'Invalid']];
        }

        if (! $session) {
            $query = ChargingSession::query()
                ->where('station_id', $station->id)
                ->where('user_id', $user->id)
                ->whereNull('end_time')
                ->where(function ($query): void {
                    $query->whereNull('ocpp_transaction_id')
                        ->orWhereColumn('ocpp_transaction_id', 'id');
                });

            if ($station->expectedConnectorCount() > 1) {
                $query->where('ocpp_connector_id', $connectorId);
            } else {
                $query->where(function ($query) use ($connectorId) {
                    $query->where('ocpp_connector_id', $connectorId)
                        ->orWhereNull('ocpp_connector_id');
                });
            }

            $session = $query
                ->latest('id')
                ->first();

            if ($session && $this->isProtectedFinishingSession($station, $session)) {
                $session = null;
            }
        }

        if (! $session) {
            // Incarcarea clientilor porneste doar din aplicatie (cu buget blocat din wallet).
            // O pornire locala cu tag-ul unui client, fara sesiune din aplicatie, ar livra
            // energie fara hold si fara oprire automata la buget.
            if (! config('services.ocpp.allow_local_user_start', false)) {
                Log::warning('OCPP StartTransaction rejected: no app session for user tag', [
                    'station' => $station->ocpp_identity,
                    'connector_id' => $connectorId,
                    'user_id' => $user->id,
                    'id_tag' => $idTag,
                ]);

                return ['transactionId' => 0, 'idTagInfo' => ['status' => 'Invalid']];
            }

            $session = ChargingSession::query()->create([
                'user_id' => $user->id,
                'station_id' => $station->id,
                'ocpp_connector_id' => $connectorId,
                'start_time' => $startedAt,
                'start_source' => 'ocpp',
                'ocpp_id_tag' => OcppService::idTagForUser($user),
                'meter_start_kwh' => $meterStart,
                'kwh_consumed' => 0,
                'price_per_kwh' => app(\App\Services\TariffService::class)->snapshotPriceForNewSession($user),
            ]);
        }

        // Keep the owner idTag (VOLTA…) when the charger echoes a local RFID tag.
        $ownerTag = trim((string) ($session->ocpp_id_tag ?? ''));
        $preserveOwnerTag = $ownerTag !== '' && (
            $this->userFromIdTag($ownerTag) !== null
            || strcasecmp($ownerTag, OcppService::idTagForUser($user)) === 0
        );

        $previousLive = is_array($session->live_metrics) ? $session->live_metrics : [];
        unset(
            $previousLive['energy_kwh'],
            $previousLive['previous_energy_kwh'],
            $previousLive['energy_integrated_kwh'],
            $previousLive['power_samples']
        );

        $session->update([
            'ocpp_transaction_id' => (string) $session->id,
            'ocpp_connector_id' => $connectorId,
            'ocpp_id_tag' => $preserveOwnerTag ? $ownerTag : OcppService::idTagForUser($user),
            'meter_start_kwh' => $this->resolveMeterStartForSession($session, $meterStart),
            'kwh_consumed' => 0,
            'start_time' => $session->start_time ?: $startedAt,
            'live_metrics' => array_merge(
                $previousLive,
                [
                    'energy_integrated_kwh' => 0,
                    'assigned_transaction_id' => (int) $session->id,
                ]
            ),
        ]);

        $station->rememberLocalIdTag($connectorId, $idTag);
        $this->clearConnectorLiveMeterEnergy($station, $connectorId);

        $station->update([
            'status' => Station::STATUS_CHARGING,
            'last_ocpp_message_at' => now(),
        ]);

        unset($this->lastTelemetryPollAt[$station->id . ':' . $connectorId]);
        app(OcppService::class)->queueMeterValuesTrigger($station->fresh(), $connectorId, true);

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

        $session = $this->findSessionForTransaction($station, $transactionId, $connectorId);

        // Finishing rows that already charged must only be closed via Stop / auto-finalize —
        // never absorb orphan MeterValues from a new start on the same connector.
        if (
            $session
            && $this->isProtectedFinishingSession($station, $session)
            && ($transactionId === '' || $transactionId === '0')
        ) {
            $session = null;
        }

        $energyService = app(SessionEnergyService::class);
        $isEnergyFlash = $session
            && $meterKwh !== null
            && SessionEnergyService::usesSessionRelativeRegister($session)
            && $energyService->looksLikeLifetimeRegisterFlash($session, $meterKwh);

        $liveMeter = array_filter([
            'power_kw' => $metrics['power_kw'],
            'current_a' => $metrics['current_a'],
            'voltage_v' => $metrics['voltage_v'],
            'soc_percent' => $metrics['soc_percent'],
            'temperature_c' => $metrics['temperature_c'],
            'energy_kwh' => $isEnergyFlash ? null : $meterKwh,
            'sampled_at' => $metrics['sampled_at'] ?? now()->toIso8601String(),
        ], static fn ($value) => $value !== null && $value !== '');

        if ($connectorId > 0) {
            $station->updateOcppConfigurationAtomically(function (array $configuration) use ($connectorId, $liveMeter): array {
                $connectors = is_array($configuration['connectors'] ?? null) ? $configuration['connectors'] : [];
                $connectors[$connectorId] = array_merge($connectors[$connectorId] ?? [], [
                    'connectorId' => $connectorId,
                    'live_meter' => $liveMeter,
                ]);
                $configuration['connectors'] = $connectors;

                return [
                    'last_ocpp_message_at' => now(),
                    'ocpp_configuration' => $configuration,
                ];
            });
        } else {
            $station->update(['last_ocpp_message_at' => now()]);
        }

        if ($session) {
            $session->rememberReportedTransactionId($transactionId);

            $previousLive = is_array($session->live_metrics) ? $session->live_metrics : [];
            $sessionUpdate = [
                'live_metrics' => array_filter([
                    'power_kw' => $metrics['power_kw'],
                    'current_a' => $metrics['current_a'],
                    'voltage_v' => $metrics['voltage_v'],
                    'soc_percent' => $metrics['soc_percent'],
                    'temperature_c' => $metrics['temperature_c'],
                    'previous_energy_kwh' => $isEnergyFlash
                        ? ($previousLive['previous_energy_kwh'] ?? null)
                        : ($previousLive['energy_kwh'] ?? null),
                    'energy_kwh' => $isEnergyFlash ? null : $meterKwh,
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
                    ? (string) (($isEnergyFlash ? null : $metrics['energy_kwh']) ?? $freshSession->kwh_consumed ?? '—')
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
        ) {
            // Tranzactia 0 nu a fost acceptata de noi (ex. card local respins). Sesiunea gasita
            // n-a primit niciodata StartTransaction, deci nu e ea cea oprita — nu o inchidem.
            Log::info('OCPP StopTransaction for unassigned transaction ignored', [
                'station' => $station->ocpp_identity,
                'connector_id' => $connectorId,
                'reason' => $reason !== '' ? $reason : null,
                'pending_session_id' => $session->id,
            ]);
            $station->update(['last_ocpp_message_at' => now()]);

            return ['idTagInfo' => ['status' => 'Accepted']];
        }

        if ($session && ! $session->end_time) {
            $stopSource = $this->resolveStopSourceForSession($session, $reason);
            $normalizedReason = $reason !== '' ? $reason : null;

            Log::info('OCPP StopTransaction finalized session', [
                'station' => $station->ocpp_identity,
                'connector_id' => $connectorId > 0 ? $connectorId : (int) ($session->ocpp_connector_id ?: 0),
                'transaction_id' => $transactionId !== '' ? $transactionId : '0',
                'reason' => $normalizedReason,
                'session_id' => $session->id,
                'stop_source' => $stopSource,
            ]);

            $stopContext = app(OcppSessionDebugService::class)->buildStopContext(
                $station->fresh(),
                $session,
                'StopTransaction',
                ['stop_transaction' => $payload]
            );

            app(ChargingStopService::class)->finalizeStop(
                $session,
                $station->fresh(),
                $stopSource,
                $endedAt,
                $meterStop,
                $normalizedReason,
                $stopContext
            );
        } elseif ($session) {
            Log::info('OCPP StopTransaction ignored: session already closed', [
                'station' => $station->ocpp_identity,
                'connector_id' => $connectorId,
                'transaction_id' => $transactionId !== '' ? $transactionId : '0',
                'reason' => $reason !== '' ? $reason : null,
                'session_id' => $session->id,
                // Pentru audit: diferenta dintre contorul final si ce s-a facturat.
                'meter_stop_kwh_reported' => $meterStop,
                'meter_stop_kwh_billed' => $session->meter_stop_kwh,
            ]);
        } elseif ($closedSession = $this->findClosedSessionForTransaction($station, $transactionId, $connectorId)) {
            // findSessionForTransaction only matches open rows: a StopTransaction arriving after the
            // session was already finalized (e.g. release sweep) lands here.
            Log::info('OCPP StopTransaction ignored: session already closed', [
                'station' => $station->ocpp_identity,
                'connector_id' => $connectorId,
                'transaction_id' => $transactionId,
                'reason' => $reason !== '' ? $reason : null,
                'session_id' => $closedSession->id,
                'meter_stop_kwh_reported' => $meterStop,
                'meter_stop_kwh_billed' => $closedSession->meter_stop_kwh,
            ]);

            $this->recordLateMeterStop($station, $closedSession, $meterStop, $transactionId, $reason);
        } else {
            Log::warning('OCPP StopTransaction ignored: no matching session', [
                'station' => $station->ocpp_identity,
                'connector_id' => $connectorId,
                'transaction_id' => $transactionId !== '' ? $transactionId : '0',
                'reason' => $reason !== '' ? $reason : null,
            ]);
        }

        $stopIdTag = strtoupper(trim((string) ($payload['idTag'] ?? '')));
        if ($stopIdTag !== '') {
            $station->rememberLocalIdTag(
                (int) ($session?->ocpp_connector_id ?: ($connectorId > 0 ? $connectorId : 1)),
                $stopIdTag
            );
        }

        $station->update([
            'last_ocpp_message_at' => now(),
        ]);

        // Free the exact connector that stopped. Prefer the session's port, then the
        // payload connector. Never blindly default to port 1 on a dual-port charger.
        $releaseConnectorId = (int) ($session?->ocpp_connector_id ?: $connectorId);
        if ($releaseConnectorId > 0) {
            $station->markConnectorAvailable($releaseConnectorId);
        }

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

            if (in_array($status, [OcppCommand::STATUS_REJECTED, OcppCommand::STATUS_FAILED], true)) {
                $command->failPendingDependents(
                    'Comanda parinte a esuat: ' . $command->action . ' (' . $status . ')'
                );
            }

            if ($status === OcppCommand::STATUS_ACCEPTED && $command->action === 'Reset') {
                $delaySeconds = max(1, (int) config('services.ocpp.remote_start_after_recovery_seconds', 10));
                $command->releaseDelayedDependents(now()->addSeconds($delaySeconds));
            }

            if (
                $status === OcppCommand::STATUS_ACCEPTED
                && $command->action === 'ChangeAvailability'
                && ($command->payload['type'] ?? null) === 'Operative'
            ) {
                // Unlock dependents that were held only on Operative (no Reset in chain).
                $command->releaseDelayedDependents(now()->addSeconds(
                    max(1, (int) config('services.ocpp.remote_start_after_recovery_seconds', 10))
                ));
            }

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

            $command->failPendingDependents(
                'Comanda parinte a esuat: ' . $command->action . ' (' . $errorCode . ')'
            );
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
                || ($client['close_code'] ?? null) !== null
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
                if (! $this->send($id, [2, $command->message_uid, $command->action, $payload])) {
                    // Socket mort: comanda ramane pending si pleaca la reconectare.
                    $this->disconnectClient($id);

                    continue 2;
                }

                $command->update([
                    'status' => OcppCommand::STATUS_SENT,
                    'sent_at' => now(),
                ]);
                $this->recordMessage($station, 'outbound', $command->message_uid, $command->action, $command->payload ?? [], OcppCommand::STATUS_SENT);
            }
        }
    }

    /** Request a normal stop after a continuous EV pause; final billing waits for the meter. */
    private function stopSuspendedEvSessions(): void
    {
        $now = microtime(true);
        if ($now - $this->lastSuspendedSweepAt < 1) {
            return;
        }
        $this->lastSuspendedSweepAt = $now;
        $seconds = (int) config('services.ocpp.suspended_ev_stop_seconds', 120);
        if ($seconds <= 0) {
            return;
        }

        foreach ($this->clients as $client) {
            $station = $client['station'] ?? null;
            if (! ($client['handshaken'] ?? false) || ! ($client['boot_received'] ?? false) || ! $station instanceof Station) {
                continue;
            }
            try {
                $station = $station->fresh();
                if (! $station || ! $station->isOcppOnline()) {
                    continue;
                }
                $connectors = $station->ocpp_configuration['connectors'] ?? [];
                $sessions = ChargingSession::query()->where('station_id', $station->id)
                    ->whereNull('end_time')->get();
                foreach ($sessions as $session) {
                    if (! $session->stoppedAfterEvPause()) {
                        $connector = $connectors[(int) ($session->ocpp_connector_id ?: 1)] ?? [];
                        if (($connector['status'] ?? null) !== 'SuspendedEV' || empty($connector['suspended_ev_since'])) {
                            continue;
                        }
                        $since = Carbon::parse($connector['suspended_ev_since']);
                        if ($since->greaterThan(now()->subSeconds($seconds))
                            || ! $session->hasConfirmedOcppTransaction() || ! $session->start_time
                            || $session->start_time->greaterThan(now()->subSeconds($seconds))) {
                            continue;
                        }
                        // Once the deadline expires, the old session must finish even if the EV resumes.
                        $session->update(['stop_source' => ChargingSession::STOP_SOURCE_SUSPENDED_EV]);
                    }
                    app(ChargingStopService::class)->requestStop($session, $station, ChargingSession::STOP_SOURCE_SUSPENDED_EV);
                }
            } catch (Throwable $exception) {
                Log::error('OCPP SuspendedEV auto-stop failed', [
                    'station_id' => $station?->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Inchide sesiunile cu tranzactie OCPP al caror conector e eliberat, dupa ce
     * fereastra de asteptare pentru StopTransaction a expirat fara ca acesta sa vina.
     */
    private function finalizeReleasedSessions(): void
    {
        $now = microtime(true);

        if ($now - $this->lastReleasedSweepAt < 10) {
            return;
        }

        $this->lastReleasedSweepAt = $now;
        $stopService = app(ChargingStopService::class);

        foreach ($this->clients as $client) {
            $station = $client['station'] ?? null;

            if (! ($client['handshaken'] ?? false) || ! $station instanceof Station) {
                continue;
            }

            try {
                $sessions = ChargingSession::query()
                    ->where('station_id', $station->id)
                    ->whereNull('end_time')
                    ->whereNotNull('ocpp_transaction_id')
                    ->get();

                foreach ($sessions as $session) {
                    $fresh = $station->fresh();
                    $connectorId = max(1, (int) ($session->ocpp_connector_id ?: 1));
                    $status = $fresh->connectorOcppStatus($connectorId);

                    if (! in_array($status, ['Available', 'Finishing'], true)) {
                        continue;
                    }

                    // Strict sesiunea cu tranzactie verificata aici, nu „ultima de pe port”
                    // (aceea poate fi o pornire noua din aplicatie, inca fara tranzactie).
                    if (! $stopService->shouldAutoFinalizeOnConnectorRelease($session->fresh(), $fresh, $status)) {
                        continue;
                    }

                    $stopService->finalizeStop(
                        $session,
                        $fresh,
                        'ocpp',
                        null,
                        null,
                        null,
                        app(OcppSessionDebugService::class)->buildStopContext(
                            $fresh,
                            $session,
                            'ReleasedWithoutStopTransaction:' . $status,
                            ['connector_id' => $connectorId]
                        )
                    );

                    Log::warning('OCPP session finalized without StopTransaction', [
                        'station' => $fresh->ocpp_identity,
                        'session_id' => $session->id,
                        'connector_id' => $connectorId,
                        'connector_status' => $status,
                    ]);
                }
            } catch (Throwable $exception) {
                Log::error('OCPP released-session sweep failed', [
                    'station_id' => $station->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Sesiunea a fost deja facturata (ex. inchisa dupa eliberarea portului) cand StopTransaction
     * aduce contorul final. Daca difera de ce s-a facturat, lasa o urma vizibila in auditul din
     * backoffice (prefix financiar, pastrat 365 de zile) ca operatorul sa poata regulariza.
     */
    private function recordLateMeterStop(
        Station $station,
        ChargingSession $session,
        ?float $meterStop,
        string $transactionId,
        string $reason,
    ): void {
        $billed = $session->meter_stop_kwh;

        if ($meterStop === null || ($billed !== null && abs($meterStop - (float) $billed) < 0.01)) {
            return;
        }

        try {
            app(\App\Services\AuditLogService::class)->record(
                action: 'charging.meter_stop_late',
                subjectType: ChargingSession::class,
                subjectId: $session->id,
                station: $station,
                session: $session,
                metadata: [
                    'transaction_id' => $transactionId !== '' ? $transactionId : '0',
                    'reason' => $reason !== '' ? $reason : null,
                    'meter_stop_kwh_reported' => $meterStop,
                    'meter_stop_kwh_billed' => $billed,
                    'difference_kwh' => $billed !== null ? round($meterStop - (float) $billed, 3) : null,
                    'kwh_billed' => $session->kwh_consumed,
                ],
            );
        } catch (Throwable $exception) {
            Log::error('OCPP late meter stop audit failed', [
                'session_id' => $session->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Elibereaza porturile si bugetele tinute de sesiuni din aplicatie care n-au pornit niciodata.
     */
    private function closeUnstartedSessions(): void
    {
        $now = microtime(true);

        if ($now - $this->lastUnstartedSweepAt < 30) {
            return;
        }

        $this->lastUnstartedSweepAt = $now;
        $stopService = app(ChargingStopService::class);

        foreach ($this->clients as $client) {
            $station = $client['station'] ?? null;

            // Only stations connected right now: their connector status is current.
            if (! ($client['handshaken'] ?? false) || ! $station instanceof Station) {
                continue;
            }

            try {
                $closed = $stopService->closeUnstartedSessions($station);

                if ($closed > 0) {
                    Log::warning('OCPP unstarted app sessions closed', [
                        'station' => $station->ocpp_identity,
                        'closed' => $closed,
                    ]);
                }
            } catch (Throwable $exception) {
                Log::error('OCPP unstarted-session sweep failed', [
                    'station_id' => $station->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function expireUnansweredCommands(): void
    {
        $now = microtime(true);

        if ($now - $this->lastCommandExpiryAt < 5) {
            return;
        }

        $this->lastCommandExpiryAt = $now;
        $timeout = max(10, (int) config('services.ocpp.command_response_timeout_seconds', 60));

        try {
            $failed = OcppCommand::failUnanswered(
                sprintf('Timeout: statia nu a raspuns in %d secunde.', $timeout),
                null,
                now()->subSeconds($timeout)
            );
        } catch (Throwable $exception) {
            Log::error('OCPP command expiry failed', ['error' => $exception->getMessage()]);

            return;
        }

        if ($failed > 0) {
            $this->warn("OCPP: {$failed} comanda/comenzi fara raspuns marcate failed (timeout).");
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

            if (is_resource($client['socket'])) {
                fclose($client['socket']);
            }
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

        if (isset($this->clients[$clientId]['socket']) && is_resource($this->clients[$clientId]['socket'])) {
            fclose($this->clients[$clientId]['socket']);
        }
        unset($this->clients[$clientId]);

        if ($stationId && ! $this->hasActiveClientForStation($stationId)) {
            Station::query()
                ->whereKey($stationId)
                ->update([
                    'ocpp_connection_status' => Station::OCPP_CONNECTION_DISCONNECTED,
                    'status' => Station::STATUS_OFFLINE,
                ]);
            // Raspunsul (CallResult) vine doar pe socketul pe care s-a trimis comanda.
            OcppCommand::failUnanswered('Conexiunea cu statia s-a inchis inainte de raspuns.', $stationId);
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

    /**
     * Pune cadrul in coada de iesire a clientului si incearca sa-l scrie imediat.
     * False doar cand socketul e mort sau coada a depasit limita.
     */
    private function send(int $clientId, array $message): bool
    {
        return $this->queueRaw($clientId, $this->encodeFrame(json_encode($message, JSON_UNESCAPED_SLASHES)));
    }

    private function queueRaw(int $clientId, string $bytes): bool
    {
        if (! isset($this->clients[$clientId]) || ! is_resource($this->clients[$clientId]['socket'])) {
            return false;
        }

        $this->clients[$clientId]['outbox'] = ($this->clients[$clientId]['outbox'] ?? '') . $bytes;

        if (strlen($this->clients[$clientId]['outbox']) > self::MAX_OUTBOX_BYTES) {
            Log::warning('OCPP outbox overflow, closing socket', ['client_id' => $clientId]);
            $this->clients[$clientId]['outbox'] = '';
            $this->markClosing($clientId, 1008);

            return false;
        }

        return $this->flushOutbox($clientId);
    }

    /**
     * Scrie cat permite socketul non-blocant; restul ramane in coada pentru bucla urmatoare.
     */
    private function flushOutbox(int $clientId): bool
    {
        $socket = $this->clients[$clientId]['socket'] ?? null;

        if (! is_resource($socket)) {
            return false;
        }

        $pending = $this->clients[$clientId]['outbox'] ?? '';

        while ($pending !== '') {
            $written = @fwrite($socket, $pending);

            if ($written === false) {
                $this->clients[$clientId]['outbox'] = '';
                $this->markClosing($clientId, 1006);

                return false;
            }

            if ($written === 0) {
                break;
            }

            $pending = (string) substr($pending, $written);
        }

        $this->clients[$clientId]['outbox'] = $pending;

        return true;
    }

    private function flushOutboxes(): void
    {
        foreach (array_keys($this->clients) as $id) {
            if (! isset($this->clients[$id])) {
                continue;
            }

            if (($this->clients[$id]['outbox'] ?? '') !== '') {
                $this->flushOutbox($id);
            }

            $this->disconnectIfClosing($id);
        }
    }

    /**
     * Inchide clientul marcat cu close_code dupa ce coada de iesire s-a golit
     * (raspunsul la Close trebuie sa ajunga la statie inainte de fclose).
     */
    private function disconnectIfClosing(int $clientId): void
    {
        if (! isset($this->clients[$clientId]) || ($this->clients[$clientId]['close_code'] ?? null) === null) {
            return;
        }

        $socketDead = ! is_resource($this->clients[$clientId]['socket']);
        $outboxEmpty = ($this->clients[$clientId]['outbox'] ?? '') === '';
        // Statia nu mai citeste: nu asteptam la nesfarsit sa plece raspunsul la Close.
        $closingTooLong = microtime(true) - (float) ($this->clients[$clientId]['closing_at'] ?? 0) > 5;

        if ($socketDead || $outboxEmpty || $closingTooLong) {
            $this->disconnectClient($clientId);
        }
    }

    private function markClosing(int $clientId, int $code): void
    {
        if (! isset($this->clients[$clientId]) || ($this->clients[$clientId]['close_code'] ?? null) !== null) {
            return;
        }

        $this->clients[$clientId]['close_code'] = $code;
        $this->clients[$clientId]['closing_at'] = microtime(true);
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
        if ($idTag === '') {
            return false;
        }

        if ($this->userFromIdTag($idTag)) {
            return true;
        }

        $configuration = is_array($station->ocpp_configuration) ? $station->ocpp_configuration : [];
        $tags = is_array($configuration['local_id_tags'] ?? null) ? $configuration['local_id_tags'] : [];
        $normalizedTags = [];
        foreach ($tags as $tag) {
            if (is_string($tag) || is_numeric($tag)) {
                $normalizedTags[] = strtoupper((string) $tag);
            }
        }

        if (in_array(strtoupper($idTag), $normalizedTags, true)) {
            return true;
        }

        $session = $this->findLinkableAppSession($station);
        if (! $session) {
            return false;
        }

        $expected = trim((string) ($session->ocpp_id_tag ?? ''));
        if ($expected !== '' && strcasecmp($expected, $idTag) === 0) {
            return true;
        }

        $user = User::query()->find($session->user_id);

        return $user !== null && strcasecmp(OcppService::idTagForUser($user), $idTag) === 0;
    }

    private function findPendingAppSession(Station $station, ?int $connectorId = null): ?ChargingSession
    {
        $query = ChargingSession::query()
            ->where('station_id', $station->id)
            ->whereNull('end_time')
            ->whereNull('ocpp_transaction_id');

        if ($connectorId && $connectorId > 0) {
            return (clone $query)
                ->where('ocpp_connector_id', $connectorId)
                ->latest('id')
                ->first();
        }

        if ($station->expectedConnectorCount() > 1) {
            $matches = (clone $query)
                ->limit(2)
                ->get();

            return $matches->count() === 1 ? $matches->first() : null;
        }

        return $query->latest('id')->first();
    }

    /**
     * Finishing + already delivered energy: leave untouched by StartTransaction / orphan meters.
     * Auto-finalize / StopTransaction remain the only writers for these rows.
     * A brand-new pending start on a still-Finishing connector must NOT be protected.
     */
    private function isProtectedFinishingSession(Station $station, ChargingSession $session): bool
    {
        if ($session->stoppedAfterEvPause()) {
            return true;
        }

        $connectorId = (int) ($session->ocpp_connector_id ?? 0);
        if ($connectorId <= 0) {
            return false;
        }

        if ($station->connectorOcppStatus($connectorId) !== 'Finishing') {
            return false;
        }

        // New app start waiting for StartTransaction while the port is still Finishing.
        if ($session->ocpp_transaction_id === null) {
            return false;
        }

        if ((float) $session->kwh_consumed > 0.01) {
            return true;
        }

        $live = is_array($session->live_metrics) ? $session->live_metrics : [];

        if ((float) ($live['energy_integrated_kwh'] ?? 0) >= 0.01) {
            return true;
        }

        return $session->start_time
            && $session->start_time->diffInMinutes(now()) >= 2;
    }

    /**
     * App session waiting for StartTransaction.conf, including when MeterValues arrived first
     * and pre-assigned ocpp_transaction_id to the session primary key.
     */
    private function findLinkableAppSession(Station $station, ?int $connectorId = null): ?ChargingSession
    {
        $session = $this->findSessionFromRecentRemoteStart($station, (int) ($connectorId ?? 0))
            ?? $this->findPendingAppSession($station, $connectorId)
            ?? $this->findMeterPreassignedAppSession($station, $connectorId);

        if ($session && $this->isProtectedFinishingSession($station, $session)) {
            return null;
        }

        return $session;
    }

    /**
     * Session targeted by a recent RemoteStart / RequestStart for this connector.
     */
    private function findSessionFromRecentRemoteStart(Station $station, int $connectorId = 0): ?ChargingSession
    {
        $commands = OcppCommand::query()
            ->where('station_id', $station->id)
            ->whereIn('action', ['RemoteStartTransaction', 'RequestStartTransaction'])
            ->whereIn('status', [
                OcppCommand::STATUS_PENDING,
                OcppCommand::STATUS_SENT,
                OcppCommand::STATUS_ACCEPTED,
            ])
            ->whereNotNull('charging_session_id')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->latest('id')
            ->limit(30)
            ->get();

        foreach ($commands as $command) {
            $payload = is_array($command->payload) ? $command->payload : [];
            $payloadConnector = (int) ($payload['connectorId'] ?? $payload['evseId'] ?? 0);

            $session = ChargingSession::query()
                ->where('id', $command->charging_session_id)
                ->where('station_id', $station->id)
                ->whereNull('end_time')
                ->first();

            if (! $session) {
                continue;
            }

            $sessionConnector = (int) ($session->ocpp_connector_id ?? 0);

            if ($connectorId > 0) {
                if ($payloadConnector > 0 && $payloadConnector !== $connectorId) {
                    continue;
                }
                if ($sessionConnector > 0 && $sessionConnector !== $connectorId) {
                    continue;
                }
            }

            $transactionId = (string) ($session->ocpp_transaction_id ?? '');
            if ($transactionId !== '' && $transactionId !== (string) $session->id) {
                continue;
            }

            if ($this->isProtectedFinishingSession($station, $session)) {
                continue;
            }

            return $session;
        }

        return null;
    }

    /**
     * Pending session that already received early MeterValues and stored ocpp_transaction_id = id.
     * Never picks a real in-progress / Finishing charge.
     */
    private function findMeterPreassignedAppSession(Station $station, ?int $connectorId = null): ?ChargingSession
    {
        $query = ChargingSession::query()
            ->where('station_id', $station->id)
            ->whereNull('end_time')
            ->whereColumn('ocpp_transaction_id', 'id')
            ->where('start_source', 'app')
            ->where('created_at', '>=', now()->subMinutes(15));

        if ($connectorId && $connectorId > 0) {
            $session = (clone $query)
                ->where('ocpp_connector_id', $connectorId)
                ->latest('id')
                ->first();

            return ($session && ! $this->isProtectedFinishingSession($station, $session))
                ? $session
                : null;
        }

        if ($station->expectedConnectorCount() > 1) {
            $matches = (clone $query)
                ->limit(2)
                ->get()
                ->filter(fn (ChargingSession $session) => ! $this->isProtectedFinishingSession($station, $session))
                ->values();

            return $matches->count() === 1 ? $matches->first() : null;
        }

        $session = $query->latest('id')->first();

        return ($session && ! $this->isProtectedFinishingSession($station, $session))
            ? $session
            : null;
    }

    private function normalizeMeterStartFromOcpp(mixed $meterStart): ?float
    {
        return SessionEnergyService::effectiveMeterStart($this->wattHoursToKwh($meterStart));
    }

    private function resolveMeterStartForSession(ChargingSession $session, ?float $meterStartFromOcpp): ?float
    {
        $fromOcpp = SessionEnergyService::effectiveMeterStart($meterStartFromOcpp);
        if ($fromOcpp !== null) {
            return $fromOcpp;
        }

        $existing = SessionEnergyService::effectiveMeterStart($session->meter_start_kwh);
        if (
            $existing !== null
            && ! app(SessionEnergyService::class)->looksLikeLifetimeRegisterFlash($session, $existing)
        ) {
            return $existing;
        }

        return null;
    }

    private function clearConnectorLiveMeterEnergy(Station $station, int $connectorId): void
    {
        $station->updateOcppConfigurationAtomically(function (array $configuration) use ($connectorId): ?array {
            $connectors = is_array($configuration['connectors'] ?? null) ? $configuration['connectors'] : [];
            $meter = is_array($connectors[$connectorId]['live_meter'] ?? null)
                ? $connectors[$connectorId]['live_meter']
                : null;

            if ($meter === null || ! array_key_exists('energy_kwh', $meter)) {
                return null;
            }

            unset($meter['energy_kwh']);
            $connectors[$connectorId]['live_meter'] = $meter;
            $configuration['connectors'] = $connectors;

            return ['ocpp_configuration' => $configuration];
        });
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
            // Do not attach orphan MeterValues to any open row on the connector —
            // that hijacks another user's Finishing / active session.
            return $this->findSessionFromRecentRemoteStart($station, $connectorId)
                ?? $this->findPendingAppSession(
                    $station,
                    $connectorId > 0 ? $connectorId : null
                );
        }

        $query = ChargingSession::query()
            ->where('station_id', $station->id)
            ->whereNull('end_time')
            ->where(function ($query) use ($transactionId): void {
                $query->where('ocpp_transaction_id', $transactionId);

                if (is_numeric($transactionId)) {
                    $query->orWhere('id', (int) $transactionId);
                }
            });

        if ($connectorId > 0) {
            $query->where('ocpp_connector_id', $connectorId);
        }

        return $query->latest('id')->first();
    }

    private function findClosedSessionForTransaction(Station $station, string $transactionId, int $connectorId = 0): ?ChargingSession
    {
        if ($transactionId === '' || $transactionId === '0') {
            return null;
        }

        $query = ChargingSession::query()
            ->where('station_id', $station->id)
            ->whereNotNull('end_time')
            ->where('ocpp_transaction_id', $transactionId);

        if ($connectorId > 0) {
            $query->where('ocpp_connector_id', $connectorId);
        }

        return $query->latest('id')->first();
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

    /**
     * Decodeaza cadrele WebSocket complete din buffer (RFC 6455) si intoarce mesajele text.
     * Cu $clientId: raspunde la Ping, inchide la Close si reasambleaza mesajele fragmentate.
     * Fara $clientId: doar cadre text complete (folosit in teste).
     *
     * @return list<string>
     */
    private function decodeFrames(string &$buffer, ?int $clientId = null): array
    {
        $messages = [];

        while (strlen($buffer) >= 2) {
            if ($clientId !== null && ($this->clients[$clientId]['close_code'] ?? null) !== null) {
                // Dupa Close nu mai procesam nimic de la acest client.
                $buffer = '';
                break;
            }

            $first = ord($buffer[0]);
            $second = ord($buffer[1]);
            $fin = ($first & 0x80) === 0x80;
            $opcode = $first & 0x0F;
            $masked = ($second & 0x80) === 0x80;
            $payloadLength = $second & 0x7F;
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

            if ($payloadLength < 0 || $payloadLength > self::MAX_MESSAGE_BYTES) {
                $this->failConnection($clientId, 1009, 'frame too large: ' . $payloadLength);
                $buffer = '';
                break;
            }

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
            $buffer = (string) substr($buffer, $offset + $payloadLength);

            if ($masked && $payloadLength > 0) {
                $payload = $payload ^ str_repeat($mask, intdiv($payloadLength, 4) + 1);
                $payload = substr($payload, 0, $payloadLength);
            }

            if ($clientId === null) {
                if ($opcode === 0x1 && $fin) {
                    $messages[] = $payload;
                }

                continue;
            }

            $message = $this->handleFrame($clientId, $fin, $opcode, $payload);

            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @return string|null mesaj text complet, daca acest cadru il finalizeaza
     */
    private function handleFrame(int $clientId, bool $fin, int $opcode, string $payload): ?string
    {
        $isControl = ($opcode & 0x08) === 0x08;

        if ($isControl) {
            if (! $fin || strlen($payload) > 125) {
                $this->failConnection($clientId, 1002, 'invalid control frame');

                return null;
            }

            if ($opcode === 0x9) {
                $this->queueRaw($clientId, $this->encodeFrame($payload, 0xA));
            } elseif ($opcode === 0x8) {
                $code = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] : 1000;
                $this->queueRaw($clientId, $this->encodeFrame(strlen($payload) >= 2 ? substr($payload, 0, 2) : '', 0x8));
                $this->markClosing($clientId, $code);
            } elseif ($opcode !== 0xA) {
                $this->failConnection($clientId, 1002, 'unknown control opcode ' . $opcode);
            }

            return null;
        }

        $fragmentOpcode = $this->clients[$clientId]['fragment_opcode'] ?? null;

        if ($opcode === 0x0) {
            if ($fragmentOpcode === null) {
                $this->failConnection($clientId, 1002, 'continuation without start frame');

                return null;
            }

            $assembled = ($this->clients[$clientId]['message_fragment'] ?? '') . $payload;

            if (strlen($assembled) > self::MAX_MESSAGE_BYTES) {
                $this->failConnection($clientId, 1009, 'fragmented message too large');

                return null;
            }

            if (! $fin) {
                $this->clients[$clientId]['message_fragment'] = $assembled;

                return null;
            }

            $this->clients[$clientId]['message_fragment'] = '';
            $this->clients[$clientId]['fragment_opcode'] = null;

            return $fragmentOpcode === 0x1 ? $assembled : null;
        }

        if ($opcode !== 0x1 && $opcode !== 0x2) {
            $this->failConnection($clientId, 1002, 'unknown data opcode ' . $opcode);

            return null;
        }

        if ($fragmentOpcode !== null) {
            $this->failConnection($clientId, 1002, 'new data frame inside fragmented message');

            return null;
        }

        if (! $fin) {
            $this->clients[$clientId]['message_fragment'] = $payload;
            $this->clients[$clientId]['fragment_opcode'] = $opcode;

            return null;
        }

        // OCPP-J foloseste doar cadre text; cadrele binare sunt ignorate.
        return $opcode === 0x1 ? $payload : null;
    }

    private function failConnection(?int $clientId, int $code, string $reason): void
    {
        if ($clientId === null || ! isset($this->clients[$clientId])) {
            return;
        }

        $station = $this->clients[$clientId]['station'] ?? null;
        Log::warning('OCPP websocket protocol error', [
            'station' => $station instanceof Station ? $station->ocpp_identity : null,
            'code' => $code,
            'reason' => $reason,
        ]);

        $alreadyClosing = ($this->clients[$clientId]['close_code'] ?? null) !== null;
        $this->markClosing($clientId, $code);

        if (! $alreadyClosing) {
            $this->queueRaw($clientId, $this->encodeFrame(pack('n', $code), 0x8));
        }
    }

    private function encodeFrame(string $payload, int $opcode = 0x1): string
    {
        $length = strlen($payload);
        $head = chr(0x80 | $opcode);

        if ($length <= 125) {
            return $head . chr($length) . $payload;
        }

        if ($length <= 65535) {
            return $head . chr(126) . pack('n', $length) . $payload;
        }

        return $head . chr(127) . pack('NN', 0, $length) . $payload;
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
        if ($session->stoppedAfterEvPause()) {
            return ChargingSession::STOP_SOURCE_SUSPENDED_EV;
        }

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

        if (! app(OcppService::class)->canAutomaticallyResetForConnector($station->fresh(), $connectorId)) {
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
