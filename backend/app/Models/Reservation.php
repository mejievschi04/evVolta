<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_NO_SHOW = 'no_show';

    /** @var list<string> */
    public const BLOCKING_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'user_id',
        'station_id',
        'connector_id',
        'ocpp_reservation_id',
        'id_tag',
        'starts_at',
        'ends_at',
        'status',
        'fee_amount',
        'fee_charged',
        'no_show_fee_amount',
        'no_show_charged',
        'charging_session_id',
        'cancelled_at',
        'completed_at',
        'metadata',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'fee_amount' => 'float',
        'no_show_fee_amount' => 'float',
        'fee_charged' => 'boolean',
        'no_show_charged' => 'boolean',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    public function chargingSession()
    {
        return $this->belongsTo(ChargingSession::class);
    }

    public function graceEndsAt(): \Illuminate\Support\Carbon
    {
        $graceMinutes = (int) ($this->station?->reservation_grace_minutes ?? 20);

        return $this->ends_at->copy()->addMinutes($graceMinutes);
    }

    public function isBlocking(): bool
    {
        return in_array($this->status, self::BLOCKING_STATUSES, true);
    }

    public function isWithinStartWindow(?\DateTimeInterface $at = null): bool
    {
        $at = $at ? \Illuminate\Support\Carbon::parse($at) : now();

        return $at->greaterThanOrEqualTo($this->starts_at)
            && $at->lessThanOrEqualTo($this->graceEndsAt());
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', self::BLOCKING_STATUSES);
    }

    public function scopeOverlapping(
        Builder $query,
        int $stationId,
        int $connectorId,
        \DateTimeInterface $startsAt,
        \DateTimeInterface $endsAt,
        ?int $ignoreId = null,
    ): Builder {
        return $query
            ->where('station_id', $stationId)
            ->where('connector_id', $connectorId)
            ->blocking()
            ->when($ignoreId, fn (Builder $builder) => $builder->where('id', '!=', $ignoreId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
    }
}
