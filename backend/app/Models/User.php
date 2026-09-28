<?php

namespace App\Models;

use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\StationFavorite;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, SoftDeletes;

    public const ACCOUNT_TYPE_PERSONAL = 'personal';

    public const ACCOUNT_TYPE_CUSTOMER = 'customer';

    public const ACCOUNT_TYPE_SERVICE = 'service';

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'currency',
        'email',
        'phone',
        'password',
        'wallet_balance',
        'google_id',
        'apple_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'is_admin',
        'google_id',
        'apple_id',
        'legal_accepted_ip',
        'legal_accepted_user_agent',
    ];

    protected $appends = [
        'auth_providers',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'legal_accepted_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'account_type' => 'string',
            'wallet_balance' => 'float',
        ];
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function isPersonalAccount(): bool
    {
        return $this->account_type === self::ACCOUNT_TYPE_PERSONAL;
    }

    public function isCustomerAccount(): bool
    {
        return $this->account_type === self::ACCOUNT_TYPE_CUSTOMER;
    }

    public function isServiceAccount(): bool
    {
        return $this->account_type === self::ACCOUNT_TYPE_SERVICE;
    }

    public function isFreeCharging(): bool
    {
        return $this->isServiceAccount();
    }

    /**
     * Prepaid wallet plans (Personal + Client). Named distinctly from card rails.
     */
    public function usesPrepaidWallet(): bool
    {
        return $this->isCustomerAccount() || $this->isPersonalAccount();
    }

    /**
     * @deprecated Use usesPrepaidWallet() — historically meant "wallet prepaid", not a bank card.
     */
    public function usesCardPayment(): bool
    {
        return $this->usesPrepaidWallet();
    }

    public function usesMonthlyBilling(): bool
    {
        return false;
    }

    /**
     * @return array{password: bool, google: bool, apple: bool, requires_password_to_delete: bool}
     */
    public function getAuthProvidersAttribute(): array
    {
        $hasGoogle = filled($this->attributes['google_id'] ?? null);
        $hasApple = filled($this->attributes['apple_id'] ?? null);

        return [
            'password' => ! $hasGoogle && ! $hasApple,
            'google' => $hasGoogle,
            'apple' => $hasApple,
            'requires_password_to_delete' => ! $hasGoogle && ! $hasApple,
        ];
    }

    public function usesPasswordForDeletion(): bool
    {
        return blank($this->google_id) && blank($this->apple_id);
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null || $this->trashed();
    }

    public function sessions()
    {
        return $this->hasMany(ChargingSession::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function walletTopups()
    {
        return $this->hasMany(WalletTopup::class);
    }

    public function walletRefunds()
    {
        return $this->hasMany(WalletRefund::class);
    }

    public function stationFavorites()
    {
        return $this->hasMany(StationFavorite::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
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

    public function hasAcceptedCurrentLegal(): bool
    {
        return $this->legal_accepted_at !== null
            && $this->legal_version === (string) config('legal.version');
    }
}
