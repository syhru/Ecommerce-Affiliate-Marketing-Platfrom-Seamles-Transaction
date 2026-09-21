<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /**
     * Canonical order status vocabulary (WS-03 §3.1 / PA-F-02).
     *
     * `verified` — not `paid` — is the canonical post-payment status. The
     * progression is:
     *
     *     pending → verified → processing → shipped → completed
     *
     * with `cancelled` as the terminal state for a cancellation made before
     * completion. `completed` and `cancelled` are both terminal.
     */
    public const string STATUS_PENDING    = 'pending';
    public const string STATUS_VERIFIED   = 'verified';
    public const string STATUS_PROCESSING = 'processing';
    public const string STATUS_SHIPPED    = 'shipped';
    public const string STATUS_COMPLETED  = 'completed';
    public const string STATUS_CANCELLED  = 'cancelled';

    /**
     * Statuses an order must be in to be completed. `completed` itself is not
     * listed here — a repeated completion is an idempotent no-op, not a
     * re-credit, and is handled separately in OrderService::markCompleted().
     */
    public const array COMPLETABLE_STATUSES = [
        self::STATUS_SHIPPED,
    ];

    /**
     * Statuses from which an order may be cancelled. A completed order is
     * terminal: post-completion refunds/returns are a separate flow that is
     * out of scope for WS-03.
     */
    public const array CANCELLABLE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_PROCESSING,
        self::STATUS_SHIPPED,
    ];

    protected $fillable = [
        'order_number',
        'customer_id',
        'affiliate_id',
        'subtotal',
        'commission_amount',
        'shipping_cost',
        'total_amount',
        'status',
        'payment_method',
        'midtrans_transaction_id',
        'midtrans_snap_token',
        'payment_verified_at',
        'shipping_address',
        'shipping_courier',
        'shipping_tracking_number',
        'shipped_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'notes',
    ];

    protected $casts = [
        'subtotal'            => 'decimal:2',
        'commission_amount'   => 'decimal:2',
        'shipping_cost'       => 'decimal:2',
        'total_amount'        => 'decimal:2',
        'payment_verified_at' => 'datetime',
        'shipped_at'          => 'datetime',
        'completed_at'        => 'datetime',
        'cancelled_at'        => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }


    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affiliate_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function isPaid(): bool
    {
        return $this->payment_verified_at !== null;
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function trackingLogs(): HasMany
    {
        return $this->hasMany(TrackingLog::class);
    }

    public function commission(): HasOne
    {
        return $this->hasOne(AffiliateCommission::class);
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    protected static function booted()
    {
        static::creating(function ($order) {
            if (empty($order->shipping_tracking_number) && !empty($order->shipping_courier)) {
                $courier = strtoupper($order->shipping_courier);
                $prefix = 'TRK';

                if (str_contains($courier, 'JNE')) {
                    $prefix = 'JNE';
                } elseif (str_contains($courier, 'J&T') || str_contains($courier, 'JNT')) {
                    $prefix = 'JNT';
                } elseif (str_contains($courier, 'SICEPAT')) {
                    $prefix = 'SCP';
                } elseif (str_contains($courier, 'POS')) {
                    $prefix = 'POS';
                }

                do {
                    $randomNumber = (string) random_int(1000000000, 9999999999);
                    $trackingNumber = $prefix . $randomNumber;
                } while (static::where('shipping_tracking_number', $trackingNumber)->exists());

                $order->shipping_tracking_number = $trackingNumber;
            }
        });
    }
}
