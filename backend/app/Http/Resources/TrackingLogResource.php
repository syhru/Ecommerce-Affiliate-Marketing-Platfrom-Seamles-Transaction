<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrackingLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'order_id'     => $this->order_id,
            'status_title' => $this->status_title,
            'description'  => $this->description,
            'created_at'   => $this->created_at?->toISOString(),
        ];
    }
}
