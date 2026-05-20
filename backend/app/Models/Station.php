<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Station extends Model
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_CHARGING = 'charging';
    public const STATUS_OFFLINE = 'offline';

    public const OCPP_CONNECTION_NOT_CONFIGURED = 'not_configured';
    public const OCPP_CONNECTION_CONNECTED = 'connected';
    public const OCPP_CONNECTION_DISCONNECTED = 'disconnected';

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

    public function liveStatus(): array
    {
        $configuration = $this->ocpp_configuration ?? [];
        $connector = is_array($configuration['connector'] ?? null) ? $configuration['connector'] : [];
        $rawConnectorStatus = $connector['status'] ?? null;
        $lastSeenAt = $this->last_heartbeat_at ?? $this->last_ocpp_message_at;
        $heartbeatInterval = max(30, (int) config('services.ocpp.heartbeat_interval', 60));
        $secondsSinceLastSeen = $lastSeenAt ? now()->diffInSeconds($lastSeenAt) : null;
        $isGatewayMode = config('services.ocpp.mode', 'simulator') !== 'simulator';
        $isConnected = $this->ocpp_connection_status === self::OCPP_CONNECTION_CONNECTED;
        $isStale = $isConnected && ($secondsSinceLastSeen === null || $secondsSinceLastSeen > ($heartbeatInterval * 2));

        return [
            'availability' => $isStale ? 'stale' : $this->availabilityFromOcppStatus($rawConnectorStatus),
            'can_start' => $this->status === self::STATUS_AVAILABLE
                && (! $isGatewayMode || ($isConnected && ! $isStale)),
            'connection_status' => $this->ocpp_connection_status,
            'connector_id' => $connector['connectorId'] ?? $connector['connector_id'] ?? 1,
            'connector_status' => $rawConnectorStatus,
            'error_code' => $connector['errorCode'] ?? null,
            'info' => $connector['info'] ?? null,
            'last_seen_at' => $lastSeenAt?->toIso8601String(),
            'last_heartbeat_at' => $this->last_heartbeat_at?->toIso8601String(),
            'last_message_at' => $this->last_ocpp_message_at?->toIso8601String(),
            'seconds_since_last_seen' => $secondsSinceLastSeen,
            'stale' => $isStale,
            'meter_value_kwh' => $this->meter_value_kwh,
            'mode' => config('services.ocpp.mode', 'simulator'),
        ];
    }

    private function availabilityFromOcppStatus(?string $status): string
    {
        return match ($status) {
            'Available' => self::STATUS_AVAILABLE,
            'Charging', 'Preparing', 'SuspendedEV', 'SuspendedEVSE', 'Finishing' => self::STATUS_CHARGING,
            'Reserved' => 'reserved',
            'Faulted' => 'faulted',
            'Unavailable' => 'unavailable',
            default => $this->status ?: self::STATUS_OFFLINE,
        };
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
