<?php

namespace App\Services;

use App\Models\AffiliateClick;
use App\Models\AffiliateCommission;
use App\Models\AffiliateProfile;
use App\Models\AffiliateWithdrawal;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class AffiliateService
{

    public function recordCommission(Order $order): ?AffiliateCommission
    {
        if (! $order->affiliate_id) {
            return null;
        }

        $affiliateProfile = AffiliateProfile::where('user_id', $order->affiliate_id)->first();

        if (! $affiliateProfile || $affiliateProfile->status !== 'active') {
            return null;
        }

        $amount = (float) $order->commission_amount > 0
            ? (float) $order->commission_amount
            : round((float) $order->subtotal * ($affiliateProfile->commission_rate / 100), 2);

        return AffiliateCommission::create([
            'order_id'        => $order->id,
            'affiliate_id'    => $order->affiliate_id,
            'amount'          => $amount,
            'commission_rate' => $affiliateProfile->commission_rate,
            'status'          => 'pending',
        ]);
    }

    public function earnCommission(AffiliateCommission $commission): void
    {
        DB::transaction(function () use ($commission) {
            $commission->update([
                'status'    => 'earned',
                'earned_at' => now(),
            ]);

            AffiliateProfile::where('user_id', $commission->affiliate_id)
                ->increment('balance', $commission->amount);

            AffiliateProfile::where('user_id', $commission->affiliate_id)
                ->increment('total_earned', $commission->amount);
        });
    }

    /**
     * Create a withdrawal request and deduct affiliate balance.
     */
    public function processWithdrawal(AffiliateProfile $profile, float $amount, array $bankData): AffiliateWithdrawal
    {
        return DB::transaction(function () use ($profile, $amount, $bankData) {
            if ($profile->balance < $amount) {
                throw new \InvalidArgumentException('Saldo tidak mencukupi.');
            }

            $profile->decrement('balance', $amount);

            return AffiliateWithdrawal::create([
                'affiliate_id'        => $profile->user_id,
                'amount'              => $amount,
                'status'              => 'pending',
                'bank_name'           => $bankData['bank_name'],
                'bank_account_number' => $bankData['bank_account_number'],
                'bank_account_holder' => $bankData['bank_account_holder'],
            ]);
        });
    }

    /**
     * Build dashboard statistics for an affiliate.
     */
    public function getStats(AffiliateProfile $profile): array
    {
        $clicks      = AffiliateClick::where('affiliate_id', $profile->user_id)->count();
        $conversions = AffiliateCommission::where('affiliate_id', $profile->user_id)->count();

        return [
            'total_clicks'      => $clicks,
            'total_conversions' => $conversions,
            'conversion_rate'   => $clicks > 0 ? round(($conversions / $clicks) * 100, 1) : 0,
            'total_commission'  => AffiliateCommission::where('affiliate_id', $profile->user_id)
                                        ->whereIn('status', ['earned', 'withdrawn'])
                                        ->sum('amount'),
            'balance'           => $profile->balance,
        ];
    }

    /**
     * Build click + conversion chart data for the last N days.
     */
    public function getChartData(AffiliateProfile $profile, int $days = 7): array
    {
        $labels = [];
        $clicks = [];
        $convs  = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date     = now()->subDays($i)->toDateString();
            $labels[] = now()->subDays($i)->format('d/m');

            $clicks[] = AffiliateClick::where('affiliate_id', $profile->user_id)
                ->whereDate('clicked_at', $date)->count();

            $convs[] = AffiliateCommission::where('affiliate_id', $profile->user_id)
                ->whereDate('created_at', $date)->count();
        }

        return compact('labels', 'clicks', 'convs');
    }
}
