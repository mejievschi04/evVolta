<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Station extends Model
{
    /** Masina conectata la conector (OCPP 1.6J). */
    public const VEHICLE_CONNECTED_STATUSES = ['Preparing', 'SuspendedEV', 'SuspendedEVSE', 'Charging'];

    /** Cablu conectat, pregatit pentru pornire (fara sesiune activa pe acel port). */
    public const PLUGGED_CONNECTOR_STATUSES = ['Preparing', 'SuspendedEV', 'SuspendedEVSE'];

    /** Conector disponibil pentru RemoteStart. */
    public const STARTABLE_CONNECTOR_STATUSES = ['Available', 'Preparing', 'SuspendedEV', 'SuspendedEVSE'];

    public const BLOCKED_START_STATUSES = ['Charging', 'Faulted', 'Unavailable', 'Reserved'];

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_CHARGING = 'charging';
    public const STATUS_OFFLINE = 'offline';

    public const OCPP_CONNECTION_NOT_CONFIGURED = 'not_configured';
    public const OCPP_CONNECTION_CONNECTED = 'connected';
    public const OCPP_CONNECTION_DISCONNECTED = 'disconnected';

    public function appearsConnectedToGateway(): bool
    {
        if ($this->ocpp_connection_status === self::OCPP_CONNECTION_CONNECTED) {
            return true;
        }

        $lastSeen = $this->last_ocpp_message_at ?? $this->last_heartbeat_at;
        $graceSeconds = max(90, (int) config('services.ocpp.heartbeat_interval', 60) * 2);

        return $lastSeen !== null && $lastSeen->greaterThan(now()->subSeconds($graceSeconds));
    }

    protected $fillable = [
        'name',
        'location',
        'latitude',
        'longitude',
        'status',
        'qr_code',
        'ocpp_identity',
        'ocpp_version',
        'ocpp_connection_status',
        'last_heartbeat_at',
        'last_ocpp_message_at',
        'meter_value_kwh',
        'ocpp_configuration',
        'power_kw',
        'connector_type',
        'currency',
    ];

    protected $casts = [
        'last_heartbeat_at' => 'datetime',
        'last_ocpp_message_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'meter_value_kwh' => 'float',
        'ocpp_configuration' => 'array',
    ];

    public function liveStatus(?int $connectorId = null): array
    {
        $configuration = is_array($this->ocpp_configuration) ? $this->ocpp_configuration : [];
        $connectors = $this->normalizedConnectors($configuration);
        $selectedConnectorId = $connectorId ?? $this->defaultConnectorId($connectors);
        $selectedConnector = $connectors[$selectedConnectorId] ?? null;
        $rawConnectorStatus = $selectedConnector['status'] ?? null;
        $lastSeenAt = $this->last_heartbeat_at ?? $this->last_ocpp_message_at;
        $heartbeatInterval = max(30, (int) config('services.ocpp.heartbeat_interval', 60));
        $secondsSinceLastSeen = $lastSeenAt ? now()->diffInSeconds($lastSeenAt) : null;
        $isGatewayMode = config('services.ocpp.mode', 'simulator') !== 'simulator';
        $appearsConnected = $this->appearsConnectedToGateway();
        $isStale = $appearsConnected
            && ($secondsSinceLastSeen === null || $secondsSinceLastSeen > ($heartbeatInterval * 2));
        $connectedConnectorId = $this->detectConnectedConnectorId($connectors);
        $connectorSummaries = $this->connectorsLiveSummary($connectors, $isGatewayMode, $appearsConnected, $isStale);

        return [
            'availability' => $isStale ? 'stale' : $this->availabilityFromOcppStatus($rawConnectorStatus),
            'can_start' => $connectedConnectorId
                && $this->connectorCanStart($connectedConnectorId)
                && (! $isGatewayMode || ($appearsConnected && ! $isStale)),
            'connection_status' => $appearsConnected
                ? self::OCPP_CONNECTION_CONNECTED
                : $this->ocpp_connection_status,
            'connector_id' => $selectedConnectorId,
            'connector_status' => $rawConnectorStatus,
            'connected_connector_id' => $connectedConnectorId,
            'connected_connector_label' => $connectedConnectorId
                ? self::connectorPortLabel($connectedConnectorId)
                : null,
            'connectors' => $connectorSummaries,
            'error_code' => $selectedConnector['errorCode'] ?? null,
            'info' => $selectedConnector['info'] ?? null,
            'last_seen_at' => $lastSeenAt?->toIso8601String(),
            'last_heartbeat_at' => $this->last_heartbeat_at?->toIso8601String(),
            'last_message_at' => $this->last_ocpp_message_at?->toIso8601String(),
            'seconds_since_last_seen' => $secondsSinceLastSeen,
            'stale' => $isStale,
            'meter_value_kwh' => $this->meter_value_kwh,
            'mode' => config('services.ocpp.mode', 'simulator'),
            ...$this->liveMeterFields($configuration),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function liveMeterFields(array $configuration): array
    {
        $liveMeter = is_array($configuration['live_meter'] ?? null) ? $configuration['live_meter'] : [];

        return [
            'power_kw' => isset($liveMeter['power_kw']) ? (float) $liveMeter['power_kw'] : null,
            'current_a' => isset($liveMeter['current_a']) ? (float) $liveMeter['current_a'] : null,
            'voltage_v' => isset($liveMeter['voltage_v']) ? (float) $liveMeter['voltage_v'] : null,
            'soc_percent' => isset($liveMeter['soc_percent']) ? (float) $liveMeter['soc_percent'] : null,
            'meter_sampled_at' => $liveMeter['sampled_at'] ?? null,
        ];
    }

    private function availabilityFromOcppStatus(?string $status): string
    {
        return match ($status) {
            'Available' => self::STATUS_AVAILABLE,
            'Preparing' => 'preparing',
            'Charging', 'SuspendedEV', 'SuspendedEVSE', 'Finishing' => self::STATUS_CHARGING,
            'Reserved' => 'reserved',
            'Faulted' => 'faulted',
            'Unavailable' => 'unavailable',
            default => $this->status ?: self::STATUS_OFFLINE,
        };
    }

    private function connectorDisplayAvailability(string $status, int $connectorId): string
    {
        if ($status === 'Finishing' && ! $this->hasActiveSessionOnConnector($connectorId)) {
            return 'preparing';
        }

        return $this->availabilityFromOcppStatus($status);
    }

    public function connectorOcppStatus(?int $connectorId = null): ?string
    {
        $configuration = is_array($this->ocpp_configuration) ? $this->ocpp_configuration : [];
        $connectors = $this->normalizedConnectors($configuration);
        $connectorId ??= $this->defaultConnectorId($connectors);

        return $connectors[$connectorId]['status'] ?? null;
    }

    public function isVehicleConnectedStatus(?string $status): bool
    {
        return in_array($status, self::VEHICLE_CONNECTED_STATUSES, true);
    }

    public function isPluggedConnectorStatus(?string $status): bool
    {
        return in_array($status, self::PLUGGED_CONNECTOR_STATUSES, true);
    }

    public function connectorCanStart(?int $connectorId): bool
    {
        $status = $this->connectorOcppStatus($connectorId);

        if ($status === null || $status === '' || in_array($status, self::BLOCKED_START_STATUSES, true)) {
            return false;
        }

        // EU1060: Finishing stale fara sesiune activa — permitem RemoteStart.
        if ($status === 'Finishing' && $this->hasActiveSessionOnConnector((int) $connectorId)) {
            return false;
        }

        return true;
    }

    public function hasActiveSessionOnConnector(int $connectorId): bool
    {
        return ChargingSession::query()
            ->where('station_id', $this->id)
            ->whereNull('end_time')
            ->where('ocpp_connector_id', $connectorId)
            ->exists();
    }

    public function canAcceptRemoteStart(?int $connectorId = null): bool
    {
        return $this->connectorCanStart($connectorId);
    }

    /**
     * Detecteaza conectorul cu masina conectata (OCPP sau inferenta dual A/B).
     */
    public function detectConnectedConnectorId(?array $connectors = null): ?int
    {
        if ($connectors === null) {
            $configuration = is_array($this->ocpp_configuration) ? $this->ocpp_configuration : [];
            $connectors = $this->normalizedConnectors($configuration);
        }

        $plugged = $this->pluggedConnectorIds($connectors);
        if (count($plugged) === 1) {
            return $plugged[0];
        }

        if (count($plugged) > 1 || count($connectors) < 2) {
            return null;
        }

        $available = collect($connectors)
            ->filter(static fn (array $connector) => ($connector['status'] ?? '') === 'Available');
        $occupied = collect($connectors)
            ->filter(function (array $connector) {
                $status = (string) ($connector['status'] ?? '');

                return $status !== ''
                    && $status !== 'Available'
                    && ! in_array($status, ['Faulted', 'Unavailable'], true);
            });

        if ($available->count() === 1 && $occupied->count() === 1) {
            return (int) $occupied->keys()->first();
        }

        return null;
    }

    public function vehicleConnectedConnectorId(?array $connectors = null): ?int
    {
        return $this->detectConnectedConnectorId($connectors);
    }

    /** @deprecated Use detectConnectedConnectorId() */
    public function connectedConnectorId(?array $connectors = null): ?int
    {
        return $this->detectConnectedConnectorId($connectors);
    }

    /**
     * @return list<int>
     */
    public function pluggedConnectorIds(?array $connectors = null): array
    {
        if ($connectors === null) {
            $configuration = is_array($this->ocpp_configuration) ? $this->ocpp_configuration : [];
            $connectors = $this->normalizedConnectors($configuration);
        }

        return collect($connectors)
            ->filter(fn (array $connector) => $this->isPluggedConnectorStatus($connector['status'] ?? null))
            ->keys()
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    public function vehicleConnectedConnectorIds(?array $connectors = null): array
    {
        $detected = $this->detectConnectedConnectorId($connectors);

        return $detected ? [$detected] : [];
    }

    /**
     * @return list<int>
     */
    public function activelyDeliveringConnectorIds(?array $connectors = null): array
    {
        if ($connectors === null) {
            $configuration = is_array($this->ocpp_configuration) ? $this->ocpp_configuration : [];
            $connectors = $this->normalizedConnectors($configuration);
        }

        return collect($connectors)
            ->filter(function (array $connector): bool {
                return in_array((string) ($connector['status'] ?? ''), ['Charging', 'SuspendedEV', 'SuspendedEVSE'], true);
            })
            ->keys()
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public static function connectorPortLabel(int $connectorId): string
    {
        return $connectorId === 2 ? 'B' : 'A';
    }

    public function resolveStartConnectorId(?int $requested = null): int
    {
        $configuration = is_array($this->ocpp_configuration) ? $this->ocpp_configuration : [];
        $connectors = $this->normalizedConnectors($configuration);
        $connectedId = $this->detectConnectedConnectorId($connectors);

        if ($connectedId && $this->connectorCanStart($connectedId)) {
            return $connectedId;
        }

        if (count($connectors) < 2) {
            foreach ($connectors as $connectorId => $connector) {
                if ($this->connectorCanStart((int) $connectorId)) {
                    return (int) $connectorId;
                }
            }
        }

        throw new \RuntimeException(
            'Conectorul nu a fost detectat. Conecteaza cablul si asteapta ca statia sa fie pregatita.',
            422
        );
    }

    public function localIdTagForConnector(int $connectorId): ?string
    {
        $configuration = is_array($this->ocpp_configuration) ? $this->ocpp_configuration : [];
        $tags = is_array($configuration['local_id_tags'] ?? null) ? $configuration['local_id_tags'] : [];

        return isset($tags[$connectorId]) ? (string) $tags[$connectorId] : null;
    }

    public function updateConnectorOcppStatus(int $connectorId, string $status): void
    {
        if ($connectorId <= 0 || $status === '') {
            return;
        }

        $configuration = is_array($this->ocpp_configuration) ? $this->ocpp_configuration : [];
        $connectors = $this->normalizedConnectors($configuration);

        $connectors[$connectorId] = array_merge($connectors[$connectorId] ?? [], [
            'connectorId' => $connectorId,
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
        ]);

        $configuration['connectors'] = $connectors;
        $configuration['connector'] = $connectors[$connectorId];

        $this->update([
            'status' => self::aggregateStationStatus($connectors, $this->status ?: self::STATUS_OFFLINE),
            'ocpp_configuration' => $configuration,
            'last_ocpp_message_at' => now(),
        ]);
    }

    public function rememberLocalIdTag(int $connectorId, string $idTag): void
    {
        $idTag = strtoupper(trim($idTag));

        if ($connectorId <= 0 || $idTag === '') {
            return;
        }

        $configuration = is_array($this->ocpp_configuration) ? $this->ocpp_configuration : [];
        $tags = is_array($configuration['local_id_tags'] ?? null) ? $configuration['local_id_tags'] : [];
        $tags[$connectorId] = $idTag;
        $configuration['local_id_tags'] = $tags;

        $this->update(['ocpp_configuration' => $configuration]);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<int, array<string, mixed>>
     */
    private function normalizedConnectors(array $configuration): array
    {
        $connectors = is_array($configuration['connectors'] ?? null) ? $configuration['connectors'] : [];
        $legacy = is_array($configuration['connector'] ?? null) ? $configuration['connector'] : [];

        if ($legacy !== []) {
            $legacyId = (int) ($legacy['connectorId'] ?? $legacy['connector_id'] ?? 1);
            $connectors[$legacyId] = array_merge($connectors[$legacyId] ?? [], $legacy);
        }

        ksort($connectors);

        return $connectors;
    }

    /**
     * @param  array<int, array<string, mixed>>  $connectors
     */
    private function defaultConnectorId(array $connectors): int
    {
        if ($connectors === []) {
            return 1;
        }

        return (int) array_key_first($connectors);
    }

    /**
     * @param  array<int, array<string, mixed>>  $connectors
     * @return list<array<string, mixed>>
     */
    private function connectorsLiveSummary(array $connectors, bool $isGatewayMode, bool $isConnected, bool $isStale): array
    {
        if ($connectors === []) {
            return [];
        }

        return collect($connectors)
            ->map(function (array $connector, int $id) use ($isGatewayMode, $isConnected, $isStale) {
                $status = (string) ($connector['status'] ?? '');
                $vehicleConnected = $this->isVehicleConnectedStatus($status)
                    || ($status === 'Finishing' && ! $this->hasActiveSessionOnConnector($id));
                $canStart = $this->connectorCanStart($id)
                    && (! $isGatewayMode || ($isConnected && ! $isStale));

                return [
                    'id' => $id,
                    'label' => self::connectorPortLabel($id),
                    'status' => $status,
                    'availability' => $this->connectorDisplayAvailability($status, $id),
                    'vehicle_connected' => $vehicleConnected,
                    'can_start' => $canStart,
                    'is_stale_finishing' => $status === 'Finishing' && ! $this->hasActiveSessionOnConnector($id),
                ];
            })
            ->values()
            ->all();
    }

    public static function aggregateStationStatus(array $connectors, string $fallbackStatus): string
    {
        $statuses = array_values(array_filter(array_map(
            static fn (array $connector) => $connector['status'] ?? null,
            $connectors
        )));

        if ($statuses === []) {
            return $fallbackStatus;
        }

        foreach (['Charging', 'SuspendedEV', 'SuspendedEVSE', 'Finishing'] as $busyStatus) {
            if (in_array($busyStatus, $statuses, true)) {
                return self::STATUS_CHARGING;
            }
        }

        if (in_array('Preparing', $statuses, true) || in_array('Available', $statuses, true)) {
            return self::STATUS_AVAILABLE;
        }

        return self::STATUS_OFFLINE;
    }

    public function markConnectorAvailable(?int $connectorId = null): void
    {
        $configuration = is_array($this->ocpp_configuration) ? $this->ocpp_configuration : [];
        $connectors = $this->normalizedConnectors($configuration);
        $connectorId ??= $this->defaultConnectorId($connectors);

        if ($connectorId <= 0) {
            $this->update(['status' => self::STATUS_AVAILABLE]);

            return;
        }

        $connectors[$connectorId] = array_merge($connectors[$connectorId] ?? [], [
            'connectorId' => $connectorId,
            'status' => 'Available',
            'timestamp' => now()->toIso8601String(),
        ]);

        $configuration['connectors'] = $connectors;
        $configuration['connector'] = $connectors[$connectorId];

        $this->update([
            'status' => self::aggregateStationStatus($connectors, self::STATUS_AVAILABLE),
            'ocpp_configuration' => $configuration,
        ]);
    }

    /**
     * @return list<string>
     */
    public function scanTokens(): array
    {
        $configuration = is_array($this->ocpp_configuration) ? $this->ocpp_configuration : [];
        $qrCode = trim((string) ($this->qr_code ?? ''));
        $tokens = array_filter([
            $qrCode !== '' ? $qrCode : null,
            $qrCode !== '' && str_starts_with(strtolower($qrCode), 'station:')
                ? substr($qrCode, strlen('station:'))
                : null,
            trim((string) ($this->ocpp_identity ?? '')) ?: null,
            trim((string) ($configuration['chargePointSerialNumber'] ?? '')) ?: null,
            trim((string) ($configuration['chargeBoxSerialNumber'] ?? '')) ?: null,
        ]);

        return array_values(array_unique($tokens));
    }

    public function sessions()
    {
        return $this->hasMany(ChargingSession::class);
    }

    public function favorites()
    {
        return $this->hasMany(StationFavorite::class);
    }

    public function ocppMessages()
    {
        return $this->hasMany(OcppMessage::class);
    }

    public function ocppCommands()
    {
        return $this->hasMany(OcppCommand::class);
    }
}
