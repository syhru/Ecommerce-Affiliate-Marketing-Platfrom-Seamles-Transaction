<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AffiliateCommissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'affiliate_id' => $this->affiliate_id,
            'amount' => $this->amount,
            'commission_rate' => $this->commission_rate,
            'status' => $this->status,
            'earned_at' => $this->earned_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'order' => $this->order ? [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
            ] : null,
        ];
    }
}
