<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletRefund extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_topup_id',
        'amount',
        'currency',
        'status',
        'payment_provider',
        'stripe_refund_id',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function walletTopup()
    {
        return $this->belongsTo(WalletTopup::class);
    }
}
