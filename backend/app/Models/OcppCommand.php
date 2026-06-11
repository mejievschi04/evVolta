<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcppCommand extends Model
{
    /** @var list<string> */
    public const HIGH_PRIORITY_ACTIONS = [
        'RemoteStartTransaction',
        'RequestStartTransaction',
        'RemoteStopTransaction',
        'RequestStopTransaction',
        'Reset',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'station_id',
        'charging_session_id',
        'message_uid',
        'action',
        'status',
        'payload',
        'response_payload',
        'error_message',
        'available_at',
        'sent_at',
        'acknowledged_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'response_payload' => 'array',
        'available_at' => 'datetime',
        'sent_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    public function chargingSession()
    {
        return $this->belongsTo(ChargingSession::class);
    }

    public function scopeReadyToSend($query)
    {
        return $query
            ->where('status', self::STATUS_PENDING)
            ->where(fn ($inner) => $inner
                ->whereNull('available_at')
                ->orWhere('available_at', '<=', now()));
    }

    public function scopeOrderByDispatchPriority($query)
    {
        $quoted = implode("','", self::HIGH_PRIORITY_ACTIONS);

        return $query
            ->orderByRaw("CASE WHEN action IN ('{$quoted}') THEN 0 ELSE 1 END")
            ->orderBy('id');
    }
}
