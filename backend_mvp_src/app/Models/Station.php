<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Station extends Model
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_CHARGING = 'charging';
    public const STATUS_OFFLINE = 'offline';

    protected $fillable = [
        'name',
        'location',
        'status',
        'qr_code',
    ];

    public function sessions()
    {
        return $this->hasMany(ChargingSession::class);
    }
}
