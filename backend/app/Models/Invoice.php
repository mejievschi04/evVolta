<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'user_id',
        'month',
        'currency',
        'invoice_type',
        'invoice_number',
        'source_session_id',
        'period_start',
        'period_end',
        'total_kwh',
        'total_amount',
        'sessions_count',
        'status',
        'payment_provider',
        'payment_session_id',
        'paid_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'source_session_id' => 'integer',
        'total_kwh' => 'float',
        'total_amount' => 'float',
        'sessions_count' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sourceSession()
    {
        return $this->belongsTo(ChargingSession::class, 'source_session_id');
    }
}
