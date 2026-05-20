<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'actor_user_id',
        'action',
        'subject_type',
        'subject_id',
        'station_id',
        'charging_session_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    public function session()
    {
        return $this->belongsTo(ChargingSession::class, 'charging_session_id');
    }
}
