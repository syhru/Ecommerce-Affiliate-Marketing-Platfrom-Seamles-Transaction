<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateWithdrawal extends Model
{
    /**
     * WS-03 withdrawal lifecycle: `pending` is the only processable state.
     * `completed` and `rejected` are terminal for normal admin processing
     * (§5.5 / AC-18).
     */
    public const string STATUS_PENDING   = 'pending';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_REJECTED  = 'rejected';

    protected $fillable = [
        'affiliate_id',
        'amount',
        'status',
        'bank_name',
        'bank_account_number',
        'bank_account_holder',
        'processed_at',
        'processed_by',
        'rejection_reason',
        'notes',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    //Relations
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affiliate_id');
    }


    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }
}