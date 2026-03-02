<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'email'            => $this->email,
            'role'             => $this->role,
            'telegram_chat_id' => $this->telegram_chat_id,
            'is_active'        => $this->is_active,
            'created_at'       => $this->created_at?->toISOString(),

            'affiliate_profile' => $this->when(
                $this->relationLoaded('affiliateProfile'),
                fn () => new AffiliateProfileResource($this->affiliateProfile)
            ),
        ];
    }
}
