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
        'series',
        'source_session_id',
        'wallet_topup_id',
        'period_start',
        'period_end',
        'total_kwh',
        'total_amount',
        'sessions_count',
        'line_description',
        'unit',
        'quantity',
        'unit_price',
        'vat_rate',
        'amount_net',
        'amount_vat',
        'buyer_name',
        'buyer_email',
        'buyer_idno',
        'seller_name',
        'seller_idno',
        'seller_vat_code',
        'issued_at',
        'status',
        'payment_provider',
        'payment_session_id',
        'paid_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'source_session_id' => 'integer',
        'wallet_topup_id' => 'integer',
        'total_kwh' => 'float',
        'total_amount' => 'float',
        'sessions_count' => 'integer',
        'quantity' => 'float',
        'unit_price' => 'float',
        'vat_rate' => 'float',
        'amount_net' => 'float',
        'amount_vat' => 'float',
        'issued_at' => 'datetime',
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

    public function walletTopup()
    {
        return $this->belongsTo(WalletTopup::class);
    }
}
