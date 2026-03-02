<?php

namespace App\Jobs;

use App\Models\AffiliateClick;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAffiliateClick implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int    $affiliateId,
        public readonly string $ipAddress,
        public readonly string $userAgent,
        public readonly string $referrerUrl,
    ) {}

    public function handle(): void
    {
        AffiliateClick::create([
            'affiliate_id' => $this->affiliateId,
            'ip_address'   => $this->ipAddress,
            'user_agent'   => $this->userAgent,
            'referrer_url' => $this->referrerUrl,
            'clicked_at'   => now(),
        ]);
    }
}
