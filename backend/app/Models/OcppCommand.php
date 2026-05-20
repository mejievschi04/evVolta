<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcppCommand extends Model
{
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
}
