<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcppMessage extends Model
{
    protected $fillable = [
        'station_id',
        'direction',
        'message_uid',
        'action',
        'status',
        'error_code',
        'error_description',
        'payload',
        'received_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
    ];

    public function station()
    {
        return $this->belongsTo(Station::class);
    }
}
