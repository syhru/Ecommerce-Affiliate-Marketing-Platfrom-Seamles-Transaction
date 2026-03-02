<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class AffiliateProfile extends Model
{
    protected $fillable = [
        'user_id',
        'referral_code',
        'commission_rate',
        'balance',
        'total_earned',
        'status',
        'approved_at',
        'approved_by',
        'bank_name',
        'bank_account_number',
        'bank_account_holder',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

 
    public function commissions(): HasManyThrough
    {
        return $this->hasManyThrough(
            AffiliateCommission::class,
            User::class,
            'id',         
            'affiliate_id', 
            'user_id',       
            'id'             
        );
    }

    public function affiliateClicks(): HasManyThrough
    {
        return $this->hasManyThrough(
            AffiliateClick::class,
            User::class,
            'id',
            'affiliate_id',
            'user_id',
            'id'
        );
    }


    public static function generateReferralCode(): string
    {
        do {
            $code = 'REF' . Str::upper(Str::random(8));
        } while (self::where('referral_code', $code)->exists());

        return $code;
    }
}

