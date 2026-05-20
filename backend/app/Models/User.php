<?php

namespace App\Models;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\StationFavorite;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'currency',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function sessions()
    {
        return $this->hasMany(ChargingSession::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function stationFavorites()
    {
        return $this->hasMany(StationFavorite::class);
    }

    public function getDisplayNameAttribute(): string
    {
        $displayName = trim(implode(' ', array_filter([
            $this->first_name,
            $this->last_name,
        ])));

        return $displayName !== '' ? $displayName : (string) $this->name;
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
