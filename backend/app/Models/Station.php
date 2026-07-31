<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Station extends Model
{
    /** Masina conectata la conector (OCPP 1.6J). */
    public const VEHICLE_CONNECTED_STATUSES = ['Preparing', 'SuspendedEV', 'SuspendedEVSE', 'Charging'];

    /** Cablu conectat, pregatit pentru pornire (fara sesiune activa pe acel port). */
    public const PLUGGED_CONNECTOR_STATUSES = ['Preparing', 'SuspendedEV', 'SuspendedEVSE', 'Finishing'];

    /** Conector disponibil pentru RemoteStart. */
    public const STARTABLE_CONNECTOR_STATUSES = ['Available', 'Preparing', 'SuspendedEV', 'SuspendedEVSE', 'Finishing'];

    public const BLOCKED_START_STATUSES = ['Charging', 'Faulted', 'Unavailable', 'Reserved'];

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_CHARGING = 'charging';
    public const STATUS_OFFLINE = 'offline';

    public const OCPP_CONNECTION_NOT_CONFIGURED = 'not_configured';
    public const OCPP_CONNECTION_CONNECTED = 'connected';
    public const OCPP_CONNECTION_DISCONNECTED = 'disconnected';

    public function appearsConnectedToGateway(): bool
    {
        $lastSeen = $this->last_ocpp_message_at ?? $this->last_heartbeat_at;
        $graceSeconds = max(90, (int) config('services.ocpp.heartbeat_interval', 60) * 2);

        if ($lastSeen === null || ! $lastSeen->greaterThan(now()->subSeconds($graceSeconds))) {
            return false;
        }

        return in_array($this->ocpp_connection_status, [
            self::OCPP_CONNECTION_CONNECTED,
            self::OCPP_CONNECTION_DISCONNECTED,
        ], true);
    }

    /**
     * Statia poate fi folosita in UI / start incarcare doar cand OCPP e online.
     */
    public function isOcppOnline(): bool
    {
        if (config('services.ocpp.mode', 'simulator') === 'simulator') {
            return true;
        }

        if ($this->ocpp_connection_status !== self::OCPP_CONNECTION_CONNECTED) {
            return false;
        }

        $lastSeen = $this->last_ocpp_message_at ?? $this->last_heartbeat_at;
        $graceSeconds = max(90, (int) config('services.ocpp.heartbeat_interval', 60) * 2);

        return $lastSeen !== null && $lastSeen->greaterThan(now()->subSeconds($graceSeconds));
    }

    /**
     * Status afisat in backoffice / API (nu poate fi disponibila fara OCPP).
     */
    public function displayStatus(): string
    {
        $availability = (string) ($this->liveStatus()['availability'] ?? self::STATUS_OFFLINE);

        return match ($availability) {
            self::STATUS_AVAILABLE, 'preparing' => self::STATUS_AVAILABLE,
            self::STATUS_CHARGING => self::STATUS_CHARGING,
            default => self::STATUS_OFFLINE,
        };
    }

    /**
     * Public mobile payload — never expose ocpp_identity, qr_code, or full OCPP config.
     *
     * @return array<string, mixed>
     */
    public function toMobileApiArray(?User $user = null, bool $isFavorite = false): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'location' => $this->location,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status,
            'power_kw' => $this->power_kw,
            'connector_type' => $this->connector_type,
            'currency' => $this->currency,
            'is_favorite' => $isFavorite,
            'ocpp_online' => $this->isOcppOnline(),
            'live_status' => $this->liveStatus(null, $user),
            'display_status' => $this->displayStatus(),
            'reservation_policy' => $this->reservationPolicy(),
        ];
    }

    /**
     * Actualizeaza DB cand gateway-ul s-a oprit dar statusul a ramas "connected".
     */
    public static function markStaleOcppConnectionsOffline(): int
    {
        if (config('services.ocpp.mode', 'simulator') === 'simulator') {
            return 0;
        }

        $graceSeconds = max(90, (int) config('services.ocpp.heartbeat_interval', 60) * 2);
        $cutoff = now()->subSeconds($graceSeconds);

        return self::query()
            ->where('ocpp_connection_status', self::OCPP_CONNECTION_CONNECTED)
            ->whereRaw('COALESCE(last_ocpp_message_at, last_heartbeat_at) < ?', [$cutoff])
            ->update([
                'ocpp_connection_status' => self::OCPP_CONNECTION_DISCONNECTED,
                'status' => self::STATUS_OFFLINE,
            ]);
    }

    protected $fillable = [
        'name',
        'location',
        'latitude',
        'longitude',
        'status',
        'qr_code',
        'ocpp_identity',
        'ocpp_auth_password',
        'ocpp_version',
        'ocpp_connection_status',
        'last_heartbeat_at',
        'last_ocpp_message_at',
        'meter_value_kwh',
        'ocpp_configuration',
        'power_kw',
        'connector_type',
        'currency',
        'reservations_enabled',
        'reservation_require_for_start',
        'reservation_fee',
        'reservation_no_show_fee',
        'reservation_max_duration_minutes',
        'reservation_advance_days',
        'reservation_grace_minutes',
    ];

    protected $hidden = [
        'ocpp_auth_password',
    ];

    protected $casts = [
        'last_heartbeat_at' => 'datetime',
        'last_ocpp_message_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'reservations_enabled' => 'boolean',
        'reservation_require_for_start' => 'boolean',
        'reservation_fee' => 'float',
        'reservation_no_show_fee' => 'float',
        'reservation_max_duration_minutes' => 'integer',
        'reservation_advance_days' => 'integer',
        'reservation_grace_minutes' => 'integer',
        'meter_value_kwh' => 'float',
        'ocpp_configuration' => 'array',
    ];

    public function liveStatus(?int $connectorId = null, ?User $user = null): array
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
        $isOnline = $this->isOcppOnline();
        $isStale = $isGatewayMode && $isOnline
            && ($secondsSinceLastSeen === null || $secondsSinceLastSeen > ($heartbeatInterval * 2));
        $connectedConnectorId = $this->detectConnectedConnectorId($connectors);
        $startCandidates = $user ? $this->startConnectorCandidatesForUser($user) : [];
        $connectorSummaries = $this->connectorsLiveSummary($connectors, $isGatewayMode, $isOnline, $isStale, $user);

        // Station-level availability (no specific connector requested) must reflect ALL ports:
        // a dual-port charger is still "available" while one port is free, even if the other charges.
        $availability = $connectorId === null
            ? $this->aggregateAvailability($connectors)
            : $this->availabilityFromOcppStatus($rawConnectorStatus);

        if ($isGatewayMode && (! $isOnline || $isStale)) {
            $availability = self::STATUS_OFFLINE;
        } elseif ($isStale) {
            $availability = 'stale';
        }

        return [
            'availability' => $availability,
            'can_start' => (
                    count($startCandidates) > 0
                    || (
                        $user === null
                        && collect($connectorSummaries)->contains(
                            static fn (array $connector): bool => ($connector['can_start'] ?? false) === true
                        )
                    )
                )
                && (! $isGatewayMode || ($isOnline && ! $isStale)),
            'requires_connector_selection' => count($startCandidates) > 1,
            'auto_start_connector_id' => count($startCandidates) === 1 ? $startCandidates[0] : null,
            'start_connector_candidates' => $this->startConnectorCandidateOptions($user),
            'plugged_connector_ids' => $this->pluggedConnectorIds($connectors),
            'connection_status' => $isGatewayMode
                ? ($isOnline
                    ? self::OCPP_CONNECTION_CONNECTED
                    : ($this->ocpp_connection_status ?: self::OCPP_CONNECTION_DISCONNECTED))
                : ($this->ocpp_connection_status ?: self::OCPP_CONNECTION_NOT_CONFIGURED),
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
            'meter_value_kwh' => $connectorId !== null
                ? (isset($selectedConnector['live_meter']['energy_kwh'])
                    ? (float) $selectedConnector['live_meter']['energy_kwh']
                    : null)
                : null,
            'mode' => config('services.ocpp.mode', 'simulator'),
            ...$this->liveMeterFields($configuration, $selectedConnector, $connectorId !== null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function connectorLiveMeter(int $connectorId): array
    {
        $configuration = is_array($this->ocpp_configuration) ? $this->ocpp_configuration : [];
        $connectors = $this->normalizedConnectors($configuration);
        $meter = $connectors[$connectorId]['live_meter'] ?? null;

        return is_array($meter) ? $meter : [];
    }

    public function connectorLiveMeterEnergy(int $connectorId): ?float
    {
        $meter = $this->connectorLiveMeter($connectorId);

        return isset($meter['energy_kwh']) ? (float) $meter['energy_kwh'] : null;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>|null  $connector
     */
    private function liveMeterFields(array $configuration, ?array $connector = null, bool $connectorScoped = false): array
    {
        if (! $connectorScoped) {
            return [
                'power_kw' => null,
                'current_a' => null,
                'voltage_v' => null,
                'soc_percent' => null,
                'meter_sampled_at' => null,
            ];
        }

        $connectorMeter = is_array($connector['live_meter'] ?? null) ? $connector['live_meter'] : [];

        return [
            'power_kw' => isset($connectorMeter['power_kw']) ? (float) $connectorMeter['power_kw'] : null,
            'current_a' => isset($connectorMeter['current_a']) ? (float) $connectorMeter['current_a'] : null,
            'voltage_v' => isset($connectorMeter['voltage_v']) ? (float) $connectorMeter['voltage_v'] : null,
            'soc_percent' => isset($connectorMeter['soc_percent']) ? (float) $connectorMeter['soc_percent'] : null,
            'meter_sampled_at' => $connectorMeter['sampled_at'] ?? null,
        ];
    }

    /**
     * Aggregate availability across all connectors for the station-level summary.
     *
     * @param  array<int, array<string, mixed>>  $connectors
     */
    private function aggregateAvailability(array $connectors): string
    {
        if ($connectors === []) {
            return $this->availabilityFromOcppStatus(null);
        }

        $availabilities = array_map(
            fn (array $connector, int $id) => $this->connectorDisplayAvailability(
                (string) ($connector['status'] ?? ''),
                $id
            ),
            array_values($connectors),
            array_keys($connectors),
        );

        // Any free port keeps the whole station bookable/startable.
        if (in_array(self::STATUS_AVAILABLE, $availabilities, true)
            || in_array('preparing', $availabilities, true)) {
            return self::STATUS_AVAILABLE;
        }

        if (in_array(self::STATUS_CHARGING, $availabilities, true)) {
            return self::STATUS_CHARGING;
        }

        if (in_array('reserved', $availabilities, true)) {
            return 'reserved';
        }

        // Fall back to the first connector's mapped status (faulted/unavailable/offline).
        return $availabilities[0];
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

    public function connectorCanStart(?int $connectorId, ?User $user = null): bool
    {
        $status = $this->connectorOcppStatus($connectorId);

        if ($status === null || $status === '') {
            return false;
        }

        if (
            $status === 'Reserved'
            && $user
            && $this->userHasActiveReservationOnConnector($user, $connectorId)
        ) {
            return true;
        }

        if (in_array($status, self::BLOCKED_START_STATUSES, true)) {
            return false;
        }

        // EU1060: Finishing — permitem RemoteStart fortat pe acelasi port.
        // Blocam doar daca alt utilizator are sesiune deschisa pe conector.
        if ($status === 'Finishing' && $this->hasActiveSessionOnConnector((int) $connectorId)) {
            if ($user && $this->userHasActiveSessionOnConnector((int) $connectorId, (int) $user->id)) {
                return true;
            }

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

    public function connectorOccupiedByOtherUser(int $connectorId, int $userId): bool
    {
        return ChargingSession::query()
            ->where('station_id', $this->id)
            ->where('ocpp_connector_id', $connectorId)
            ->whereNull('end_time')
            ->where('user_id', '!=', $userId)
            ->exists();
    }

    public function userHasActiveSessionOnConnector(int $connectorId, int $userId): bool
    {
        return ChargingSession::query()
            ->where('station_id', $this->id)
            ->where('ocpp_connector_id', $connectorId)
            ->where('user_id', $userId)
            ->whereNull('end_time')
            ->exists();
    }

    /**
     * Porturi pe care utilizatorul curent poate porni (nu sunt ocupate de alt user).
     *
     * @return list<int>
     */
    public function startConnectorCandidatesForUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $candidates = collect($this->expectedConnectorIds())
            ->filter(fn (int $connectorId) => $this->connectorIsStartCandidateForUser($connectorId, $user))
            ->values()
            ->all();

        $plugged = array_values(array_filter(
            $candidates,
            fn (int $connectorId) => $this->isPluggedConnectorStatus($this->connectorOcppStatus($connectorId))
        ));

        if (count($plugged) > 0) {
            return $plugged;
        }

        return $candidates;
    }

    public function connectorIsStartCandidateForUser(int $connectorId, User $user): bool
    {
        if ($this->connectorOccupiedByOtherUser($connectorId, (int) $user->id)) {
            return false;
        }

        $status = $this->connectorOcppStatus($connectorId);

        if ($status === null || $status === '') {
            return false;
        }

        // Sesiune proprie: doar pe Finishing permitem repornire fortata pe acelasi port
        // (altfel portul e ocupat de sesiunea curenta — reuse-ul se face pe conectorul rezolvat).
        if ($this->userHasActiveSessionOnConnector($connectorId, (int) $user->id)) {
            return $status === 'Finishing';
        }

        if ($this->hasActiveSessionOnConnector($connectorId)) {
            return false;
        }

        if (in_array($status, ['Charging', 'SuspendedEV', 'SuspendedEVSE'], true)) {
            return false;
        }

        if (! $this->connectorCanStart($connectorId, $user)) {
            return false;
        }

        if ($this->isPluggedConnectorStatus($status) || $status === 'Finishing') {
            return true;
        }

        return in_array($status, self::STARTABLE_CONNECTOR_STATUSES, true);
    }

    public function resolveStartConnectorIdForUser(?User $user, ?int $requested = null): int
    {
        if ($requested !== null && $requested > 0) {
            if (! $user || ! $this->connectorIsStartCandidateForUser($requested, $user)) {
                throw new \RuntimeException('Conectorul selectat nu este disponibil pentru tine.', 422);
            }

            return $requested;
        }

        if ($user) {
            $candidates = $this->startConnectorCandidatesForUser($user);

            if (count($candidates) === 1) {
                return $candidates[0];
            }

            if (count($candidates) > 1) {
                $pluggedCount = collect($candidates)
                    ->filter(fn (int $connectorId) => $this->isPluggedConnectorStatus(
                        $this->connectorOcppStatus($connectorId)
                    ))
                    ->count();

                throw new \RuntimeException(
                    $pluggedCount > 1
                        ? 'Mai multe porturi au masina conectata. Alege portul A sau B in aplicatie.'
                        : 'Alege portul A sau B in aplicatie.',
                    422
                );
            }
        }

        return $this->resolveStartConnectorId($requested);
    }

    /**
     * @return list<array{id: int, label: string, vehicle_connected: bool, status: ?string}>
     */
    public function startConnectorCandidateOptions(?User $user): array
    {
        return collect($this->startConnectorCandidatesForUser($user))
            ->map(function (int $connectorId) {
                $status = $this->connectorOcppStatus($connectorId);

                return [
                    'id' => $connectorId,
                    'label' => self::connectorPortLabel($connectorId),
                    'status' => $status,
                    'vehicle_connected' => $this->isPluggedConnectorStatus($status)
                        || $this->isVehicleConnectedStatus((string) $status),
                ];
            })
            ->values()
            ->all();
    }

    public function connectorCanReserve(?int $connectorId, ?User $user = null): bool
    {
        if ($connectorId === null || $connectorId <= 0 || ! $this->reservationsEnabled()) {
            return false;
        }

        if (! in_array($connectorId, $this->expectedConnectorIds(), true)) {
            return false;
        }

        $isGatewayMode = config('services.ocpp.mode', 'simulator') !== 'simulator';
        if ($isGatewayMode && ! $this->isOcppOnline()) {
            return false;
        }

        if ($this->hasActiveSessionOnConnector($connectorId)) {
            return false;
        }

        $status = $this->connectorOcppStatus($connectorId);
        if (in_array($status, ['Charging', 'SuspendedEV', 'SuspendedEVSE', 'Preparing', 'Reserved', 'Faulted', 'Unavailable'], true)) {
            return false;
        }

        if ($status === 'Finishing' && $this->hasActiveSessionOnConnector($connectorId)) {
            return false;
        }

        if ($this->activeReservationForConnector($connectorId, $user)) {
            return false;
        }

        return true;
    }

    public function canAcceptRemoteStart(?int $connectorId = null, ?User $user = null): bool
    {
        return $this->connectorCanStart($connectorId, $user);
    }

    public function reservationsEnabled(): bool
    {
        return (bool) $this->reservations_enabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function reservationPolicy(): array
    {
        return [
            'enabled' => $this->reservationsEnabled(),
            'require_for_start' => (bool) $this->reservation_require_for_start,
            'fee' => round((float) $this->reservation_fee, 2),
            'no_show_fee' => round((float) $this->reservation_no_show_fee, 2),
            'max_duration_minutes' => (int) $this->reservation_max_duration_minutes,
            'advance_days' => (int) $this->reservation_advance_days,
            'grace_minutes' => (int) $this->reservation_grace_minutes,
            'currency' => $this->currency ?? 'MDL',
        ];
    }

    public function userHasActiveReservationOnConnector(User $user, ?int $connectorId = null): bool
    {
        $query = Reservation::query()
            ->with('station')
            ->where('user_id', $user->id)
            ->where('station_id', $this->id)
            ->blocking()
            ->where('starts_at', '<=', now());

        if ($connectorId !== null) {
            $query->where('connector_id', $connectorId);
        }

        return $query->get()->contains(
            fn (Reservation $reservation) => $reservation->isWithinStartWindow()
        );
    }

    public function activeReservationForConnector(int $connectorId, ?User $exceptUser = null): ?Reservation
    {
        $query = Reservation::query()
            ->with('station')
            ->where('station_id', $this->id)
            ->where('connector_id', $connectorId)
            ->blocking()
            ->orderBy('starts_at');

        if ($exceptUser) {
            $query->where('user_id', '!=', $exceptUser->id);
        }

        return $query->get()->first(
            fn (Reservation $reservation) => $reservation->isWithinStartWindow()
        );
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
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
        if (
            $connectedId
            && $this->connectorCanStart($connectedId)
            && ! $this->hasActiveSessionOnConnector($connectedId)
        ) {
            return $connectedId;
        }

        if (
            $requested !== null
            && $requested > 0
            && $this->connectorCanStart($requested)
            && ! $this->hasActiveSessionOnConnector($requested)
        ) {
            return $requested;
        }

        foreach ($connectors as $connectorId => $connector) {
            $id = (int) $connectorId;
            if ($this->connectorCanStart($id) && ! $this->hasActiveSessionOnConnector($id)) {
                return $id;
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
     * Numar conectori asteptati (EU1060 dual = 2). Folosit pentru refresh OCPP si backoffice.
     */
    public function expectedConnectorCount(): int
    {
        $configuration = is_array($this->ocpp_configuration) ? $this->ocpp_configuration : [];

        return $this->expectedConnectorCountFromConfiguration($configuration);
    }

    /**
     * @return list<int>
     */
    public function expectedConnectorIds(): array
    {
        return range(1, $this->expectedConnectorCount());
    }

    /**
     * @param  array<string, mixed>|null  $configuration
     * @return array<int, array<string, mixed>>
     */
    public function normalizedConnectorsForConfiguration(?array $configuration = null): array
    {
        $configuration ??= is_array($this->ocpp_configuration) ? $this->ocpp_configuration : [];

        return $this->normalizedConnectors($configuration);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function expectedConnectorCountFromConfiguration(array $configuration): int
    {
        if (isset($configuration['NumberOfConnectors'])) {
            return max(1, (int) $configuration['NumberOfConnectors']);
        }

        $connectors = is_array($configuration['connectors'] ?? null) ? $configuration['connectors'] : [];
        $maxId = $connectors === [] ? 0 : max(array_map('intval', array_keys($connectors)));
        $model = strtolower((string) ($configuration['chargePointModel'] ?? ''));

        if (str_contains($model, '1060') || str_contains($model, 'eu1060')) {
            return max(2, $maxId);
        }

        return max(1, $maxId, count($connectors));
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

        foreach ($this->expectedConnectorIdsFromConfiguration($configuration) as $connectorId) {
            $connectors[$connectorId] = array_merge(
                ['connectorId' => $connectorId],
                $connectors[$connectorId] ?? []
            );
        }

        ksort($connectors);

        return $connectors;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<int>
     */
    private function expectedConnectorIdsFromConfiguration(array $configuration): array
    {
        return range(1, $this->expectedConnectorCountFromConfiguration($configuration));
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
    private function connectorsLiveSummary(
        array $connectors,
        bool $isGatewayMode,
        bool $isOnline,
        bool $isStale,
        ?User $user = null,
    ): array {
        if ($connectors === []) {
            return [];
        }

        return collect($connectors)
            ->map(function (array $connector, int $id) use ($isGatewayMode, $isOnline, $isStale, $user) {
                $status = (string) ($connector['status'] ?? '');
                $vehicleConnected = $isOnline
                    && ($this->isVehicleConnectedStatus($status)
                        || $this->isPluggedConnectorStatus($status)
                        || ($status === 'Finishing' && ! $this->hasActiveSessionOnConnector($id)));
                $canStart = $this->connectorCanStart($id, $user)
                    && (! $isGatewayMode || ($isOnline && ! $isStale));
                $canStartForUser = $user
                    && $this->connectorIsStartCandidateForUser($id, $user)
                    && (! $isGatewayMode || ($isOnline && ! $isStale));
                $canReserve = $this->connectorCanReserve($id, $user)
                    && (! $isGatewayMode || ($isOnline && ! $isStale));
                $availability = ($isGatewayMode && (! $isOnline || $isStale))
                    ? self::STATUS_OFFLINE
                    : $this->connectorDisplayAvailability($status, $id);

                return [
                    'id' => $id,
                    'label' => self::connectorPortLabel($id),
                    'status' => ($isGatewayMode && ! $isOnline) ? 'Offline' : ($status !== '' ? $status : '—'),
                    'availability' => $availability,
                    'vehicle_connected' => $vehicleConnected,
                    'can_start' => $canStart,
                    'can_start_for_user' => $canStartForUser,
                    'occupied_by_other_user' => $user
                        ? $this->connectorOccupiedByOtherUser($id, (int) $user->id)
                        : false,
                    'my_active_session' => $user
                        ? $this->userHasActiveSessionOnConnector($id, (int) $user->id)
                        : false,
                    'can_reserve' => $canReserve,
                    'is_stale_finishing' => $status === 'Finishing' && ! $this->hasActiveSessionOnConnector($id),
                    'has_active_session' => $this->hasActiveSessionOnConnector($id),
                    ...$this->connectorTelemetryFields(is_array($connector['live_meter'] ?? null) ? $connector['live_meter'] : []),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $liveMeter
     * @return array<string, mixed>
     */
    private function connectorTelemetryFields(array $liveMeter): array
    {
        return [
            'energy_kwh' => isset($liveMeter['energy_kwh']) ? (float) $liveMeter['energy_kwh'] : null,
            'power_kw' => isset($liveMeter['power_kw']) ? (float) $liveMeter['power_kw'] : null,
            'current_a' => isset($liveMeter['current_a']) ? (float) $liveMeter['current_a'] : null,
            'voltage_v' => isset($liveMeter['voltage_v']) ? (float) $liveMeter['voltage_v'] : null,
            'soc_percent' => isset($liveMeter['soc_percent']) ? (float) $liveMeter['soc_percent'] : null,
            'sampled_at' => $liveMeter['sampled_at'] ?? null,
        ];
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
