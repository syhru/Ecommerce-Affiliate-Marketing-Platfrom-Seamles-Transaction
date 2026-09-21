<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateClick extends Model
{

    public $timestamps = false;

    protected $fillable = [
        'affiliate_id',
        'referral_code',
        'visitor_token',
        'landing_url',
        'ip_address',
        'user_agent',
        'referrer_url',
        'clicked_at',
    ];

    protected $casts = [
        'clicked_at' => 'datetime',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affiliate_id');
    }
}