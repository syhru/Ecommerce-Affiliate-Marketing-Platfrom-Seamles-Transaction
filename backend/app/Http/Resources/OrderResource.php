<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'order_number'              => $this->order_number,
            'status'                    => $this->status,

            // Pembayaran
            'payment_method'            => $this->payment_method,
            'payment_verified_at'       => $this->payment_verified_at?->toISOString(),

            // Nominal
            'subtotal'                  => (float) $this->subtotal,
            'commission_amount'         => (float) $this->commission_amount,
            'total_amount'              => (float) $this->total_amount,

            // Pengiriman
            'shipping_address'          => $this->shipping_address,
            'shipping_courier'          => $this->shipping_courier,
            'shipping_tracking_number'  => $this->shipping_tracking_number,
            'shipped_at'                => $this->shipped_at?->toISOString(),
            'completed_at'              => $this->completed_at?->toISOString(),
            'cancelled_at'              => $this->cancelled_at?->toISOString(),
            'cancellation_reason'       => $this->cancellation_reason,
            'notes'                     => $this->notes,

            // Timestamps
            'created_at'                => $this->created_at?->toISOString(),
            'updated_at'                => $this->updated_at?->toISOString(),

            // Relasi
            'customer' => $this->when(
                $this->relationLoaded('customer'),
                fn () => new UserResource($this->customer)
            ),
            'items' => $this->when(
                $this->relationLoaded('items'),
                fn () => OrderItemResource::collection($this->items)
            ),
            'tracking_logs' => $this->when(
                $this->relationLoaded('trackingLogs'),
                fn () => TrackingLogResource::collection($this->trackingLogs)
            ),
        ];
    }
}
