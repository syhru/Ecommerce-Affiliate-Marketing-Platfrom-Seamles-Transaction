<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateCommission extends Model
{
    /**
     * WS-03 financial lifecycle: a commission is `pending` while the order it
     * belongs to is not yet completed, becomes `earned` (and credits the
     * affiliate balance exactly once) when that order completes, and becomes
     * `cancelled` when the order is cancelled before completion.
     *
     * `withdrawn` marks a commission that has been paid out by an approved
     * withdrawal (WS-03 out of scope, kept for the existing vocabulary).
     */
    public const string STATUS_PENDING    = 'pending';
    public const string STATUS_EARNED     = 'earned';
    public const string STATUS_CANCELLED  = 'cancelled';
    public const string STATUS_WITHDRAWN  = 'withdrawn';

    protected $fillable = [
        'order_id',
        'affiliate_id',
        'amount',
        'commission_rate',
        'status',
        'earned_at',
        'cancelled_at',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'earned_at'       => 'datetime',
        'cancelled_at'    => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }


    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affiliate_id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeEarned($query)
    {
        return $query->where('status', self::STATUS_EARNED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeWithdrawn($query)
    {
        return $query->where('status', self::STATUS_WITHDRAWN);
    }

    /**
     * A commission counts toward the affiliate's balance/total_earned only
     * after the order completed and the credit was applied (WS-03 §3.2).
     */
    public function isEarned(): bool
    {
        return $this->status === self::STATUS_EARNED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}