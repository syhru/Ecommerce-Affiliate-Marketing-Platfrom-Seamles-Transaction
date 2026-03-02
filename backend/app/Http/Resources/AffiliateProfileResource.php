<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AffiliateProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'user_id'              => $this->user_id,
            'referral_code'        => $this->referral_code,
            'commission_rate'      => (float) $this->commission_rate,
            'balance'              => (float) $this->balance,
            'total_earned'         => (float) $this->total_earned,
            'status'               => $this->status,
            'approved_at'          => $this->approved_at?->toISOString(),

            // Info rekening bank (hanya tampilkan jika terisi)
            'bank_name'            => $this->bank_name,
            'bank_account_number'  => $this->bank_account_number,
            'bank_account_holder'  => $this->bank_account_holder,

            'created_at'           => $this->created_at?->toISOString(),
        ];
    }
}
