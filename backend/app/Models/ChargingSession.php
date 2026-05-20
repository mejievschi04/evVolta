<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargingSession extends Model
{
    protected $fillable = [
        'user_id',
        'station_id',
        'ocpp_transaction_id',
        'ocpp_id_tag',
        'meter_start_kwh',
        'meter_stop_kwh',
        'start_source',
        'stop_source',
        'start_time',
        'end_time',
        'kwh_consumed',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'kwh_consumed' => 'float',
        'meter_start_kwh' => 'float',
        'meter_stop_kwh' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    public function ocppCommands()
    {
        return $this->hasMany(OcppCommand::class);
    }
}
