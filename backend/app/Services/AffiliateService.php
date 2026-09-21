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
    public function __construct(protected NotificationService $notification) {}

    public function recordCommission(Order $order): ?AffiliateCommission
    {
        if (! $order->affiliate_id) {
            return null;
        }

        // Idempotent against duplicate webhooks and retries: one order only
        // ever gets one commission row (WS-03 §5.1).
        $existing = AffiliateCommission::where('order_id', $order->id)->first();

        if ($existing) {
            return $existing;
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
            'status'          => AffiliateCommission::STATUS_PENDING,
        ]);
    }

    /**
     * Earn a commission: `pending → earned`, crediting the affiliate balance
     * and total_earned exactly once (WS-03 §3.2 / AC-04, AC-05, AC-06).
     *
     * Idempotent by row lock + status check: job/scheduler retries and double
     * dispatch can never double-credit.
     */
    public function earnCommission(AffiliateCommission $commission): void
    {
        DB::transaction(function () use ($commission) {
            $locked = AffiliateCommission::where('id', $commission->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== AffiliateCommission::STATUS_PENDING) {
                return;
            }

            $locked->update([
                'status'    => AffiliateCommission::STATUS_EARNED,
                'earned_at' => now(),
            ]);

            AffiliateProfile::where('user_id', $locked->affiliate_id)
                ->increment('balance', $locked->amount);

            AffiliateProfile::where('user_id', $locked->affiliate_id)
                ->increment('total_earned', $locked->amount);
        });
    }

    /**
     * Void a pending commission when its order is cancelled before completion
     * (WS-03 §3.3 / AC-08). Never touches the balance: a pending commission
     * was never credited. Idempotent.
     */
    public function cancelCommission(AffiliateCommission $commission): void
    {
        DB::transaction(function () use ($commission) {
            $locked = AffiliateCommission::where('id', $commission->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== AffiliateCommission::STATUS_PENDING) {
                return;
            }

            $locked->update([
                'status'       => AffiliateCommission::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);
        });
    }

    /**
     * Create a withdrawal request and deduct affiliate balance (WS-03 §3.4 /
     * AC-13, AC-14).
     *
     * The balance deduction and the withdrawal row are created inside one
     * transaction, and an affiliate may only hold one pending withdrawal at a
     * time, so a retried/duplicated API request can never double-deduct.
     *
     * Concurrency: requests for the same affiliate serialize on a
     * `lockForUpdate()` row lock over the canonical AffiliateProfile row,
     * acquired before the pending check, the deduction and the insert. With
     * zero pending withdrawals the previous `lockForUpdate()` over an empty
     * result set locked no row, so two concurrent requests both passed the
     * check and both inserted. Locking the parent row closes that gap: the
     * second request blocks until the first transaction commits, then sees
     * the row it created.
     *
     * @throws \InvalidArgumentException When balance is insufficient or a
     *                                   pending withdrawal already exists.
     */
    public function processWithdrawal(AffiliateProfile $profile, float $amount, array $bankData): AffiliateWithdrawal
    {
        return DB::transaction(function () use ($profile, $amount, $bankData) {
            // Serialize concurrent requests for the same affiliate on the
            // canonical profile row. This lock is held for the whole
            // transaction, so the pending check, the deduction and the insert
            // act as one atomic unit.
            $profile = AffiliateProfile::where('user_id', $profile->user_id)
                ->lockForUpdate()
                ->first();

            if (! $profile) {
                throw new \InvalidArgumentException('Profil affiliate tidak ditemukan.');
            }

            if ($profile->balance < $amount) {
                throw new \InvalidArgumentException('Saldo tidak mencukupi.');
            }

            // Re-checked under the profile lock, so a concurrent request that
            // already created the pending withdrawal is visible here.
            $existingPending = AffiliateWithdrawal::where('affiliate_id', $profile->user_id)
                ->where('status', AffiliateWithdrawal::STATUS_PENDING)
                ->exists();

            if ($existingPending) {
                throw new \InvalidArgumentException('Anda masih memiliki permintaan penarikan yang sedang diproses.');
            }

            $profile->decrement('balance', $amount);

            return AffiliateWithdrawal::create([
                'affiliate_id'        => $profile->user_id,
                'amount'              => $amount,
                'status'              => AffiliateWithdrawal::STATUS_PENDING,
                'bank_name'           => $bankData['bank_name'],
                'bank_account_number' => $bankData['bank_account_number'],
                'bank_account_holder' => $bankData['bank_account_holder'],
            ]);
        });
    }

    /**
     * Approve a pending withdrawal: `pending → completed` (WS-03 §5.5 / AC-15,
     * AC-18). The balance was already deducted at request time, so this only
     * finalizes. Idempotent: re-processing a finalized withdrawal is a no-op.
     */
    public function completeWithdrawal(AffiliateWithdrawal $withdrawal, ?int $processedBy = null): void
    {
        DB::transaction(function () use ($withdrawal, $processedBy) {
            $locked = AffiliateWithdrawal::where('id', $withdrawal->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== AffiliateWithdrawal::STATUS_PENDING) {
                return;
            }

            $locked->update([
                'status'      => AffiliateWithdrawal::STATUS_COMPLETED,
                'processed_at' => now(),
                'processed_by' => $processedBy,
            ]);
        });

        // Notify only about a state that actually committed (WS-03 §5.6).
        $fresh = $withdrawal->fresh();

        if (! $fresh || $fresh->status !== AffiliateWithdrawal::STATUS_COMPLETED) {
            return;
        }

        $profile = AffiliateProfile::where('user_id', $fresh->affiliate_id)->first();

        if ($profile) {
            $this->notification->notifyAffiliateWithdrawalProcessed($profile, $fresh, true);
        }
    }

    /**
     * Reject a pending withdrawal: `pending → rejected`, refunding the
     * affiliate balance exactly once (WS-03 §5.5 / AC-16, AC-17, AC-18).
     *
     * The refund only happens while the withdrawal is still pending, so a
     * repeated decision can never double-refund. The notification is sent
     * after the transaction commits, so it can only describe a refund that
     * actually happened.
     */
    public function rejectWithdrawal(AffiliateWithdrawal $withdrawal, string $reason, ?int $processedBy = null): void
    {
        DB::transaction(function () use ($withdrawal, $reason, $processedBy) {
            $locked = AffiliateWithdrawal::where('id', $withdrawal->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== AffiliateWithdrawal::STATUS_PENDING) {
                return;
            }

            $locked->update([
                'status'           => AffiliateWithdrawal::STATUS_REJECTED,
                'processed_at'     => now(),
                'processed_by'     => $processedBy,
                'rejection_reason' => $reason,
            ]);

            AffiliateProfile::where('user_id', $locked->affiliate_id)
                ->increment('balance', $locked->amount);
        });

        // Notify only about a state that actually committed (WS-03 §5.6).
        $fresh = $withdrawal->fresh();

        if (! $fresh || $fresh->status !== AffiliateWithdrawal::STATUS_REJECTED) {
            return;
        }

        $profile = AffiliateProfile::where('user_id', $fresh->affiliate_id)->first();

        if ($profile) {
            $this->notification->notifyAffiliateWithdrawalProcessed($profile, $fresh, false, $reason);
        }
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
