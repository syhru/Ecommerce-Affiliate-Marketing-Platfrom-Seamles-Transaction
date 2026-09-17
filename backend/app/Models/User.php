<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Support\Str;

class User extends Authenticatable implements FilamentUser, \Illuminate\Contracts\Auth\MustVerifyEmail
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

    protected static function booted(): void
    {
        static::updating(function (User $user) {
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            if ($user->isDirty('password') || $user->isDirty('role') || ($user->isDirty('is_active') && ! $user->is_active)) {
                $currentVersion = $user->credential_version ?? $user->getOriginal('credential_version') ?? 1;
                $user->credential_version = ((int) $currentVersion) + 1;
                $user->remember_token = Str::random(60);
            }
        });
        static::updated(function (User $user) {
            if ($user->wasChanged('password') || $user->wasChanged('role') || ($user->wasChanged('is_active') && ! $user->is_active)) {
                app(\App\Services\CredentialRevoker::class)->revoke($user);
            }
        });
    }

    public function credentialEpoch(): string
    {
        return (string) $this->credential_version;
    }

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
            'credential_version' => 'integer',
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

    /**
     * Batasi akses panel Filament: superadmin + active + verified.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isSuperadmin() && $this->is_active && $this->hasVerifiedEmail();
    }

    public function isSuperadmin(): bool
    {
        return $this->role === 'superadmin';
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
