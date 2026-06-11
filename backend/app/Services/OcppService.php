<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\OcppCommand;
use App\Models\OcppMessage;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class OcppService
{
    public static function idTagForUser(User $user): string
    {
        return 'VOLTA' . str_pad((string) $user->id, 8, '0', STR_PAD_LEFT);
    }

    public function queueRemoteStart(Station|int $station, ?ChargingSession $session = null, ?User $user = null): array
    {
        $station = $this->resolveStation($station);

        if ($session?->ocpp_transaction_id && $this->isSessionPhysicallyActive($session, $station)) {
            return [
                'station_id' => $station->id,
                'mode' => $this->isSimulatorMode() ? 'simulator' : 'gateway',
                'status' => 'already_started',
                'message' => 'Sesiunea de incarcare este deja activa pe statie.',
            ];
        }

        if (! $this->isSimulatorMode() && $session) {
            $existing = OcppCommand::query()
                ->where('charging_session_id', $session->id)
                ->whereIn('action', ['RemoteStartTransaction', 'RequestStartTransaction'])
                ->whereIn('status', [
                    OcppCommand::STATUS_PENDING,
                    OcppCommand::STATUS_SENT,
                    OcppCommand::STATUS_ACCEPTED,
                ])
                ->latest('id')
                ->first();

            if ($existing) {
                $payloadConnector = (int) ($existing->payload['connectorId'] ?? 1);
                $sessionConnector = max(1, (int) ($session->ocpp_connector_id ?? 1));

                if ($payloadConnector === $sessionConnector) {
                    return [
                        'station_id' => $station->id,
                        'mode' => 'gateway',
                        'status' => 'queued',
                        'command_id' => $existing->id,
                        'message_uid' => $existing->message_uid,
                        'message' => 'Comanda de pornire este deja in coada OCPP.',
                    ];
                }

                $existing->update([
                    'status' => OcppCommand::STATUS_FAILED,
                    'error_message' => 'Inlocuita de o comanda pentru alt conector.',
                    'acknowledged_at' => now(),
                ]);
            }
        }

        return $this->startTransaction($station, $session, $user);
    }

    public function requeueRemoteStartForSession(
        Station|int $station,
        ChargingSession $session,
        int $delaySeconds = 2
    ): ?OcppCommand {
        if ($this->isSimulatorMode()) {
            return null;
        }

        $station = $this->resolveStation($station);
        $session = $session->fresh();

        if ($session->end_time || $session->ocpp_transaction_id) {
            return null;
        }

        $inFlight = OcppCommand::query()
            ->where('charging_session_id', $session->id)
            ->whereIn('action', ['RemoteStartTransaction', 'RequestStartTransaction'])
            ->whereIn('status', [
                OcppCommand::STATUS_PENDING,
                OcppCommand::STATUS_SENT,
                OcppCommand::STATUS_ACCEPTED,
            ])
            ->exists();

        if ($inFlight) {
            return null;
        }

        $recentAttempts = OcppCommand::query()
            ->where('charging_session_id', $session->id)
            ->whereIn('action', ['RemoteStartTransaction', 'RequestStartTransaction'])
            ->where('created_at', '>', now()->subMinutes(3))
            ->count();

        if ($recentAttempts >= 8) {
            return null;
        }

        if (! $station->appearsConnectedToGateway()) {
            return null;
        }

        $session->loadMissing('user');
        $payload = $this->buildStartPayload($station, $session, $session->user);
        $action = $station->ocpp_version === '2.0.1'
            ? 'RequestStartTransaction'
            : 'RemoteStartTransaction';

        $command = $this->queueOutboundCommand(
            $station,
            $action,
            $payload,
            now()->addSeconds(max(1, $delaySeconds))
        );
        $command->update(['charging_session_id' => $session->id]);

        return $command;
    }

    public function startTransaction(Station|int $station, ?ChargingSession $session = null, ?User $user = null): array
    {
        $station = $this->resolveStation($station);

        if ($this->isSimulatorMode()) {
            return [
                'station_id' => $station->id,
                'mode' => 'simulator',
                'status' => 'accepted',
                'transaction_id' => 'tx-' . uniqid(),
                'message' => 'Incarcarea a pornit (simulare OCPP).',
            ];
        }

        $this->assertStationCanReceiveCommands($station);
        $station->refresh();

        $payload = $this->buildStartPayload($station, $session, $user);

        $command = $this->queueOutboundCommand(
            $station,
            $station->ocpp_version === '2.0.1' ? 'RequestStartTransaction' : 'RemoteStartTransaction',
            $payload
        );

        $command->update(['charging_session_id' => $session?->id]);

        return [
            'station_id' => $station->id,
            'mode' => 'gateway',
            'status' => 'queued',
            'command_id' => $command->id,
            'message_uid' => $command->message_uid,
            'message' => 'Comanda de pornire a fost pusa in coada OCPP.',
        ];
    }

    public function stopTransaction(Station|int $station, ?ChargingSession $session = null): array
    {
        $station = $this->resolveStation($station);

        if ($this->isSimulatorMode()) {
            return [
                'station_id' => $station->id,
                'mode' => 'simulator',
                'status' => 'accepted',
                'message' => 'Incarcarea a fost oprita (simulare OCPP).',
            ];
        }

        $this->assertStationCanReceiveCommands($station);

        $command = OcppCommand::query()->create([
            'station_id' => $station->id,
            'charging_session_id' => $session?->id,
            'message_uid' => (string) Str::uuid(),
            'action' => $station->ocpp_version === '2.0.1' ? 'RequestStopTransaction' : 'RemoteStopTransaction',
            'status' => OcppCommand::STATUS_PENDING,
            'available_at' => now(),
            'payload' => $this->buildStopPayload($station, $session),
        ]);

        return [
            'station_id' => $station->id,
            'mode' => 'gateway',
            'status' => 'queued',
            'command_id' => $command->id,
            'message_uid' => $command->message_uid,
            'message' => 'Comanda de oprire a fost pusa in coada OCPP.',
        ];
    }

    public function connectionUrl(Station $station): ?string
    {
        if (! $station->ocpp_identity) {
            return null;
        }

        return rtrim((string) config('services.ocpp.public_url'), '/') . '/' . rawurlencode($station->ocpp_identity);
    }

    public function isSimulatorMode(): bool
    {
        return config('services.ocpp.mode', 'simulator') === 'simulator';
    }

    public function ensureReadyForRemoteCommands(Station $station): void
    {
        if (! $this->isSimulatorMode()) {
            $this->assertStationCanReceiveCommands($station);
        }
    }

    public function remoteStartIdTag(Station $station, int $connectorId, ?User $user = null): string
    {
        return $this->resolveRemoteStartIdTag($station, $connectorId, null, $user);
    }

    /**
     * OCPP 1.6J: solicita StatusNotification de la statie (TriggerMessage).
     */
    public function refreshConnectorStatus(Station|int $station, int $waitMs = 0, bool $forceRefresh = false): Station
    {
        $station = $this->resolveStation($station)->fresh();

        if ($this->isSimulatorMode()) {
            return $station;
        }

        $this->assertStationCanReceiveCommands($station);

        if (! $forceRefresh && ! $this->shouldRefreshConnectorStatus($station->id)) {
            return $waitMs > 0 ? $this->waitForStationRefresh($station, $waitMs) : $station;
        }

        foreach ($station->expectedConnectorIds() as $connectorId) {
            $this->queueTriggerMessage($station, [
                'requestedMessage' => 'StatusNotification',
                'connectorId' => $connectorId,
            ]);

            $this->resetStaleFinishingConnector($station, $connectorId);
        }

        return $this->waitForStationRefresh($station, $waitMs);
    }

    /**
     * Oprire fortata pe EU1060 cand RemoteStopTransaction este respins.
     * Pune conectorul Inoperative apoi revine la Operative.
     *
     * @return array{status: string, command_ids: list<int>}
     */
    public function forceStopConnector(Station|int $station, int $connectorId, bool $softReset = true): array
    {
        $station = $this->resolveStation($station);
        $this->assertStationCanReceiveCommands($station);

        if ($connectorId <= 0) {
            throw new RuntimeException('Conector invalid.', 422);
        }

        $commandIds = [];

        $stop = $this->stopTransaction($station, null);
        $commandIds[] = (int) $stop['command_id'];

        $commandIds[] = $this->queueOutboundCommand($station, 'ChangeAvailability', [
            'connectorId' => $connectorId,
            'type' => 'Inoperative',
        ])->id;

        $commandIds[] = $this->queueOutboundCommand($station, 'ChangeAvailability', [
            'connectorId' => $connectorId,
            'type' => 'Operative',
        ], now()->addSeconds(2))->id;

        if ($softReset) {
            $commandIds[] = $this->queueOutboundCommand($station, 'Reset', [
                'type' => 'Soft',
            ], now()->addSeconds(4))->id;
        }

        return [
            'status' => 'queued',
            'station_id' => $station->id,
            'connector_id' => $connectorId,
            'command_ids' => $commandIds,
        ];
    }

    /**
     * OCPP 1.6J UnlockConnector — deblocheaza mufa blocata.
     *
     * @return array{status: string, command_id: int}
     */
    public function unlockConnector(Station|int $station, int $connectorId): array
    {
        $station = $this->resolveStation($station);
        $this->assertStationCanReceiveCommands($station);

        if ($connectorId <= 0) {
            throw new RuntimeException('Conector invalid.', 422);
        }

        $command = $this->queueOutboundCommand($station, 'UnlockConnector', [
            'connectorId' => $connectorId,
        ]);

        return [
            'status' => 'queued',
            'command_id' => $command->id,
        ];
    }

    /**
     * OCPP 1.6J GetDiagnostics — solicita jurnal diagnostic de la statie.
     *
     * @return array{status: string, command_id: int, location: string}
     */
    public function requestDiagnostics(Station|int $station, int $retries = 1): array
    {
        $station = $this->resolveStation($station);
        $this->assertStationCanReceiveCommands($station);

        $location = rtrim((string) config('services.ocpp.diagnostics_ftp_url', 'ftp://diagnostics.local/evolta/'), '/') . '/';

        $command = $this->queueOutboundCommand($station, 'GetDiagnostics', [
            'location' => $location,
            'retries' => max(0, $retries),
            'retryInterval' => 120,
            'startTime' => now()->subDay()->utc()->format('Y-m-d\TH:i:s\Z'),
            'stopTime' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);

        return [
            'status' => 'queued',
            'command_id' => $command->id,
            'location' => $location,
        ];
    }

    /**
     * EU1060: Finishing blocat fara sesiune — reset soft via ChangeAvailability.
     */
    public function resetStaleFinishingConnector(Station $station, int $connectorId): void
    {
        if ($this->isSimulatorMode() || $connectorId <= 0) {
            return;
        }

        $station = $station->fresh();
        if ($station->connectorOcppStatus($connectorId) !== 'Finishing') {
            return;
        }

        if ($station->hasActiveSessionOnConnector($connectorId)) {
            return;
        }

        if ($this->hasRecentConnectorRecovery($station->id, $connectorId, 180)) {
            return;
        }

        $this->queueConnectorAvailabilityCycle($station, $connectorId);
    }

    /**
     * EU1060: SuspendedEV / RemoteStart Rejected — ChangeAvailability + Soft Reset, apoi RemoteStart.
     *
     * @return list<int> command ids
     */
    public function recoverConnectorForRemoteStart(
        Station|int $station,
        int $connectorId,
        ?ChargingSession $session = null,
        string $reason = 'stuck',
        bool $force = false
    ): array {
        if ($this->isSimulatorMode() || $connectorId <= 0) {
            return [];
        }

        $station = $this->resolveStation($station)->fresh();

        if (! $station->appearsConnectedToGateway()) {
            return [];
        }

        $cooldownSeconds = $force ? 45 : 90;

        if (! $force && $this->hasRecentConnectorRecovery($station->id, $connectorId, $cooldownSeconds)) {
            return [];
        }

        $commandIds = $this->queueConnectorAvailabilityCycle($station, $connectorId);

        if (config('services.ocpp.soft_reset_on_start_reject', true) || $reason === 'manual') {
            $commandIds[] = $this->queueOutboundCommand($station, 'Reset', [
                'type' => 'Soft',
            ], now()->addSeconds(4))->id;
        }

        if ($session && ! $session->end_time && ! $session->ocpp_transaction_id) {
            $retry = $this->requeueRemoteStartForSession(
                $station,
                $session->fresh(),
                (int) config('services.ocpp.remote_start_after_recovery_seconds', 10)
            );

            if ($retry) {
                $commandIds[] = $retry->id;
            }
        }

        return $commandIds;
    }

    /**
     * Reset manual pe conector (ChangeAvailability + Soft Reset, optional RemoteStart).
     *
     * @return array{status: string, station_id: int, connector_id: int, command_ids: list<int>}
     */
    public function manualResetConnector(
        Station|int $station,
        int $connectorId,
        ?ChargingSession $session = null,
        bool $retryRemoteStart = true
    ): array {
        $station = $this->resolveStation($station)->fresh();
        $this->assertStationCanReceiveCommands($station);

        if ($connectorId <= 0) {
            throw new RuntimeException('Conector invalid.', 422);
        }

        $sessionForRetry = (
            $retryRemoteStart
            && $session
            && ! $session->end_time
            && ! $session->ocpp_transaction_id
        ) ? $session->fresh() : null;

        $commandIds = $this->recoverConnectorForRemoteStart(
            $station,
            $connectorId,
            $sessionForRetry,
            'manual',
            true
        );

        if ($commandIds === []) {
            throw new RuntimeException(
                'Reset indisponibil momentan. Asteapta cateva secunde sau verifica conexiunea statiei.',
                409
            );
        }

        return [
            'status' => 'queued',
            'station_id' => $station->id,
            'connector_id' => $connectorId,
            'command_ids' => $commandIds,
        ];
    }

    public function hasRecentConnectorRecovery(int $stationId, ?int $connectorId = null, int $withinSeconds = 90): bool
    {
        $since = now()->subSeconds($withinSeconds);

        $resetRecent = OcppCommand::query()
            ->where('station_id', $stationId)
            ->where('action', 'Reset')
            ->where('created_at', '>', $since)
            ->exists();

        if ($resetRecent) {
            return true;
        }

        if ($connectorId === null || $connectorId <= 0) {
            return OcppCommand::query()
                ->where('station_id', $stationId)
                ->where('action', 'ChangeAvailability')
                ->where('created_at', '>', $since)
                ->exists();
        }

        return OcppCommand::query()
            ->where('station_id', $stationId)
            ->where('action', 'ChangeAvailability')
            ->where('created_at', '>', $since)
            ->where('payload->connectorId', $connectorId)
            ->exists();
    }

    /**
     * @return list<int>
     */
    private function queueConnectorAvailabilityCycle(Station $station, int $connectorId): array
    {
        return [
            $this->queueOutboundCommand($station, 'ChangeAvailability', [
                'connectorId' => $connectorId,
                'type' => 'Inoperative',
            ])->id,
            $this->queueOutboundCommand($station, 'ChangeAvailability', [
                'connectorId' => $connectorId,
                'type' => 'Operative',
            ], now()->addSeconds(2))->id,
        ];
    }

    public function syncConnectorStateBeforeStart(Station|int $station): Station
    {
        $station = $this->resolveStation($station)->fresh();

        if ($this->isSimulatorMode()) {
            return $station;
        }

        $connectedId = $station->detectConnectedConnectorId();
        $waitMs = $connectedId
            ? max(0, (int) config('services.ocpp.start_sync_wait_connected_ms', 400))
            : max(0, (int) config('services.ocpp.start_sync_wait_ms', 1200));

        return $this->refreshConnectorStatus($station, $waitMs, true);
    }

    /**
     * OCPP 1.6J: solicita MeterValues + StatusNotification pentru sesiune activa.
     */
    public function refreshSessionTelemetry(Station|int $station, ?int $connectorId = null, int $waitMs = 0): Station
    {
        $station = $this->resolveStation($station)->fresh();

        if ($this->isSimulatorMode()) {
            return $station;
        }

        $this->assertStationCanReceiveCommands($station);

        $connectorId = $connectorId && $connectorId > 0 ? $connectorId : null;

        if (! $this->shouldRefreshSessionTelemetry($station->id, $connectorId)) {
            return $station;
        }

        $meterPayload = ['requestedMessage' => 'MeterValues'];
        if ($connectorId) {
            $meterPayload['connectorId'] = $connectorId;
        }

        $this->queueMeterValuesTrigger($station, $connectorId);

        return $this->waitForStationRefresh($station, $waitMs);
    }

    public function queueMeterValuesTrigger(Station $station, ?int $connectorId = null, bool $force = false): ?OcppCommand
    {
        if ($this->isSimulatorMode()) {
            return null;
        }

        $payload = ['requestedMessage' => 'MeterValues'];
        if ($connectorId && $connectorId > 0) {
            $payload['connectorId'] = $connectorId;
        }

        return $this->queueTriggerMessage($station, $payload, null, $force);
    }

    public function pushMeterSampleIntervalConfig(Station $station): ?OcppCommand
    {
        if ($this->isSimulatorMode()) {
            return null;
        }

        return $this->queueOutboundCommand($station, 'ChangeConfiguration', [
            'key' => 'MeterValueSampleInterval',
            'value' => (string) $this->meterValueSampleIntervalSeconds(),
        ], now()->addMilliseconds(500));
    }

    public function meterValueSampleIntervalSeconds(): int
    {
        return max(5, (int) config('services.ocpp.meter_value_sample_interval', 5));
    }

    public function queueTriggerMessage(
        Station $station,
        array $payload,
        ?\DateTimeInterface $availableAt = null,
        bool $force = false
    ): ?OcppCommand {
        $payloadKey = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($payloadKey === false) {
            return $this->queueOutboundCommand($station, 'TriggerMessage', $payload, $availableAt);
        }

        if (! $force) {
            $dedupeSeconds = ($payload['requestedMessage'] ?? '') === 'MeterValues' ? 4 : 10;

            $recent = OcppCommand::query()
                ->where('station_id', $station->id)
                ->where('action', 'TriggerMessage')
                ->where('created_at', '>', now()->subSeconds($dedupeSeconds))
                ->whereIn('status', [
                    OcppCommand::STATUS_PENDING,
                    OcppCommand::STATUS_SENT,
                    OcppCommand::STATUS_ACCEPTED,
                ])
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->first(function (OcppCommand $command) use ($payloadKey): bool {
                    $existingKey = json_encode($command->payload ?? [], JSON_UNESCAPED_SLASHES);

                    return $existingKey === $payloadKey;
                });

            if ($recent) {
                return $recent;
            }

            $pendingCount = OcppCommand::query()
                ->where('station_id', $station->id)
                ->where('action', 'TriggerMessage')
                ->where('status', OcppCommand::STATUS_PENDING)
                ->count();

            if ($pendingCount >= 4) {
                return null;
            }
        }

        return $this->queueOutboundCommand($station, 'TriggerMessage', $payload, $availableAt);
    }

    private function shouldRefreshConnectorStatus(int $stationId): bool
    {
        $seconds = max(1, (int) config('services.ocpp.refresh_status_seconds', 5));

        return Cache::add("ocpp:refresh:status:{$stationId}", true, now()->addSeconds($seconds));
    }

    private function shouldRefreshSessionTelemetry(int $stationId, ?int $connectorId): bool
    {
        $suffix = $connectorId ? (string) $connectorId : 'all';
        $seconds = max(4, $this->meterValueSampleIntervalSeconds() - 1);

        return Cache::add("ocpp:refresh:telemetry:{$stationId}:{$suffix}", true, now()->addSeconds($seconds));
    }

    private function waitForStationRefresh(Station $station, int $waitMs): Station
    {
        if ($waitMs <= 0) {
            return $station;
        }

        $pollUs = max(50, (int) config('services.ocpp.refresh_poll_interval_ms', 200)) * 1000;
        $deadline = microtime(true) + ($waitMs / 1000);
        $baselineMessageAt = $station->last_ocpp_message_at?->getTimestamp();
        $baselineConnectedId = $station->detectConnectedConnectorId();

        while (microtime(true) < $deadline) {
            usleep($pollUs);
            $station = $station->fresh();

            $messageAt = $station->last_ocpp_message_at?->getTimestamp();
            if (
                $baselineMessageAt !== null
                && $messageAt !== null
                && $messageAt > $baselineMessageAt
            ) {
                break;
            }

            $connectedId = $station->detectConnectedConnectorId();
            if ($connectedId !== null && $connectedId !== $baselineConnectedId) {
                break;
            }
        }

        return $station;
    }

    public function queueOutboundCommand(
        Station $station,
        string $action,
        array $payload,
        ?\DateTimeInterface $availableAt = null
    ): OcppCommand {
        return OcppCommand::query()->create([
            'station_id' => $station->id,
            'message_uid' => (string) Str::uuid(),
            'action' => $action,
            'status' => OcppCommand::STATUS_PENDING,
            'available_at' => $availableAt ?? now(),
            'payload' => $payload,
        ]);
    }

    private function resolveStation(Station|int $station): Station
    {
        if ($station instanceof Station) {
            return $station;
        }

        return Station::query()->findOrFail($station);
    }

    private function assertStationCanReceiveCommands(Station $station): void
    {
        if (! $station->ocpp_identity) {
            throw new RuntimeException('Statia nu are OCPP identity configurat.', 422);
        }

        if (! $station->appearsConnectedToGateway()) {
            throw new RuntimeException('Statia nu este conectata la gateway-ul OCPP.', 422);
        }
    }

    private function buildStartPayload(Station $station, ?ChargingSession $session, ?User $user): array
    {
        $connectorId = max(1, (int) ($session?->ocpp_connector_id ?? 1));
        $idTag = $this->resolveRemoteStartIdTag($station, $connectorId, $session, $user);

        if ($station->ocpp_version === '2.0.1') {
            return [
                'evseId' => $connectorId,
                'remoteStartId' => $session?->id ?? random_int(100000, 999999),
                'idToken' => [
                    'idToken' => $idTag,
                    'type' => 'Central',
                ],
            ];
        }

        return [
            'connectorId' => $connectorId,
            'idTag' => $idTag,
        ];
    }

    private function resolveRemoteStartIdTag(
        Station $station,
        int $connectorId,
        ?ChargingSession $session,
        ?User $user
    ): string {
        $localTag = $station->localIdTagForConnector($connectorId)
            ?? $this->learnLocalIdTagFromMessages($station, $connectorId);

        if ($localTag) {
            $station->rememberLocalIdTag($connectorId, $localTag);

            return strtoupper($localTag);
        }

        return $session?->ocpp_id_tag ?: ($user ? self::idTagForUser($user) : 'volta-backoffice');
    }

    private function learnLocalIdTagFromMessages(Station $station, int $connectorId): ?string
    {
        foreach (OcppMessage::query()
            ->where('station_id', $station->id)
            ->where('action', 'StartTransaction')
            ->where('direction', 'inbound')
            ->latest('id')
            ->limit(20)
            ->get() as $message) {
            $payload = is_array($message->payload) ? $message->payload : [];
            if ((int) ($payload['connectorId'] ?? 0) !== $connectorId) {
                continue;
            }

            $idTag = strtoupper(trim((string) ($payload['idTag'] ?? '')));
            if ($idTag !== '') {
                return $idTag;
            }
        }

        foreach (OcppMessage::query()
            ->where('station_id', $station->id)
            ->where('action', 'StopTransaction')
            ->where('direction', 'inbound')
            ->latest('id')
            ->limit(20)
            ->get() as $message) {
            $payload = is_array($message->payload) ? $message->payload : [];
            $idTag = strtoupper(trim((string) ($payload['idTag'] ?? '')));
            if ($idTag !== '') {
                return $idTag;
            }
        }

        return null;
    }

    private function buildStopPayload(Station $station, ?ChargingSession $session): array
    {
        $transactionId = $session?->remoteStopTransactionId() ?? 0;

        if ($station->ocpp_version === '2.0.1') {
            return [
                'transactionId' => (string) $transactionId,
            ];
        }

        return [
            'transactionId' => $transactionId,
        ];
    }

    public function sessionIsPhysicallyActive(ChargingSession $session, Station $station): bool
    {
        return $this->isSessionPhysicallyActive($session, $station);
    }

    private function isSessionPhysicallyActive(ChargingSession $session, Station $station): bool
    {
        if ((float) $session->kwh_consumed > 0) {
            return true;
        }

        $connectorId = (int) ($session->ocpp_connector_id ?: 1);
        $status = $station->connectorOcppStatus($connectorId);

        return in_array($status, ['Charging', 'SuspendedEV', 'SuspendedEVSE'], true);
    }
}
