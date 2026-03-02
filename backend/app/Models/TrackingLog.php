<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackingLog extends Model
{

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'status_title',
        'description',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // Relations
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}