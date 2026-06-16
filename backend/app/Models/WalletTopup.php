<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTopup extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'amount_refunded',
        'currency',
        'status',
        'payment_provider',
        'payment_session_id',
        'payment_intent_id',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'amount_refunded' => 'float',
        'paid_at' => 'datetime',
    ];

    public function refundableAmount(): float
    {
        return round(max(0, (float) $this->amount - (float) $this->amount_refunded), 2);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
