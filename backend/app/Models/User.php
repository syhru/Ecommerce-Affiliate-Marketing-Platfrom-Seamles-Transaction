<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
  use HasApiTokens, HasFactory, Notifiable;

    /**
     * Kolom yang boleh diisi secara mass-assignment.
     * Sesuai migrasi: users table.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'telegram_chat_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    // ──────────────────────────── Relations ────────────────────────────

    /** Profil afiliasi (jika role = affiliate) */
    public function affiliateProfile(): HasOne
    {
        return $this->hasOne(AffiliateProfile::class);
    }

    /** Semua pesanan yang dibuat oleh user ini sebagai customer */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    /** Komisi afiliasi yang dimiliki user ini */
    public function affiliateCommissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class, 'affiliate_id');
    }

    /** Permintaan penarikan dana afiliasi */
    public function affiliateWithdrawals(): HasMany
    {
        return $this->hasMany(AffiliateWithdrawal::class, 'affiliate_id');
    }

    /** Klik referral yang tercatat atas nama afiliasi ini */
    public function affiliateClicks(): HasMany
    {
        return $this->hasMany(AffiliateClick::class, 'affiliate_id');
    }

    // ──────────────────────────── Helpers ──────────────────────────────

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isAffiliate(): bool
    {
        return $this->role === 'affiliate';
    }

    public function isCustomer(): bool
    {
        return $this->role === 'customer';
    }
}
