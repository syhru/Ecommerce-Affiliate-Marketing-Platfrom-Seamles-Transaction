<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateCommission extends Model
{

    protected $fillable = [
        'order_id',
        'affiliate_id',
        'amount',
        'commission_rate',
        'status',
        'earned_at',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'earned_at'       => 'datetime',
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
        return $query->where('status', 'earned');
    }

    public function scopeWithdrawn($query)
    {
        return $query->where('status', 'withdrawn');
    }
}