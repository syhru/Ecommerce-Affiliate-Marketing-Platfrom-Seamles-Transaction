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
    /**
     * Click dedupe window (WS-04 §2.4). Repeated visits from the same
     * `visitor_token` to the same affiliate inside this window are one click.
     */
    public const int CLICK_DEDUPE_WINDOW_MINUTES = 30;

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

        if (! $affiliateProfile || $affiliateProfile->status !== AffiliateProfile::STATUS_ACTIVE) {
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
        $conversions = Order::where('affiliate_id', $profile->user_id)
            ->whereIn('status', [Order::STATUS_VERIFIED, Order::STATUS_PROCESSING, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED])
            ->whereNotNull('payment_verified_at')->count();

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
     * Canonical referral validation + deduplicated click tracking (WS-04
     * §4.1–§4.4). Public and unauthenticated; the caller supplied
     * `visitor_token` is untrusted input used only as a dedupe key.
     *
     * Eligibility is `active` only (§2.3). An unknown or non-active code returns
     * a non-successful validation result and never writes a click row.
     *
     * Dedupe: the same `visitor_token` reaching the same affiliate inside the
     * previous 30 minutes produces no new click (§2.4). IP is supporting
     * telemetry only and is never part of the identity key.
     *
     * @return array{valid: bool, click_created: bool, referral_code?: string}
     */
    public function trackReferral(string $code, string $visitorToken, array $context = []): array
    {
        $profile = AffiliateProfile::where('referral_code', $code)
            ->where('status', AffiliateProfile::STATUS_ACTIVE)
            ->first();

        if (! $profile) {
            return ['valid' => false, 'click_created' => false];
        }

        return DB::transaction(function () use ($profile, $visitorToken, $context) {
            $now = now();
            $stateQuery = DB::table('affiliate_click_dedupe_states')
                ->where('affiliate_id', $profile->user_id)
                ->where('visitor_token', $visitorToken);
            $state = $stateQuery->lockForUpdate()->first();

            if (! $state) {
                // The unique key serializes creation of the state row across
                // independent requests; insertOrIgnore lets the loser reload
                // and lock the row after the winner commits.
                DB::table('affiliate_click_dedupe_states')->insertOrIgnore([
                    'affiliate_id'  => $profile->user_id,
                    'visitor_token' => $visitorToken,
                    'last_clicked_at' => null,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
                $state = $stateQuery->lockForUpdate()->firstOrFail();
            }

            if ($state->last_clicked_at !== null
                && \Illuminate\Support\Carbon::parse($state->last_clicked_at)->greaterThanOrEqualTo($now->copy()->subMinutes(self::CLICK_DEDUPE_WINDOW_MINUTES))) {
                return ['valid' => true, 'click_created' => false, 'referral_code' => $profile->referral_code];
            }

            AffiliateClick::create([
                'affiliate_id'  => $profile->user_id,
                'referral_code' => $profile->referral_code,
                'visitor_token' => $visitorToken,
                'landing_url'   => $context['landing_url'] ?? null,
                'ip_address'    => $context['ip_address'] ?? null,
                'user_agent'    => $context['user_agent'] ?? null,
                'referrer_url'  => $context['referrer_url'] ?? null,
                'clicked_at'    => $now,
            ]);

            DB::table('affiliate_click_dedupe_states')
                ->where('affiliate_id', $profile->user_id)
                ->where('visitor_token', $visitorToken)
                ->update(['last_clicked_at' => $now, 'updated_at' => $now]);

            return ['valid' => true, 'click_created' => true, 'referral_code' => $profile->referral_code];
        });
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

    /**
     * The lifetime of a browser-stored referral (WS-04 §2.2). Every valid touch
     * resets the expiry to this many days from that touch.
     */
    public const int REFERRAL_LIFETIME_DAYS = 30;

    /** Resolve a stored browser referral, returning null for expired state. */
    public function resolveStoredReferral(?array $stored): array
    {
        if (! is_array($stored) || empty($stored['code'])) {
            return ['code' => null, 'expires_at' => null];
        }

        $expiresAt = $stored['expires_at'] ?? null;

        if (! is_numeric($expiresAt) || (int) $expiresAt < now()->getTimestamp()) {
            return ['code' => null, 'expires_at' => null];
        }

        return ['code' => (string) $stored['code'], 'expires_at' => (int) $expiresAt];
    }

    /**
     * The only affiliate status transitions the business allows (WS-04 §2.8).
     * Keys use literal status values so the matrix stays readable next to the
     * model constants. `rejected` re-enters review through the user re-apply
     * path, not through transition().
     */
    public const array ALLOWED_TRANSITIONS = [
        'pending'  => ['active', 'rejected'],
        'active'   => ['inactive'],
        'inactive' => ['active'],
    ];

    /**
     * Run the only allowed affiliate status transition from `$profile->status`
     * to `$to` (WS-04 §2.8). Rejected profiles re-enter review through the
     * user re-apply path, not through this operation.
     *
     * Guarded by the transition matrix, not by caller discipline, and the
     * profile + user role write happens inside one transaction so the two can
     * never diverge (WS-04 §5).
     *
     * @throws \DomainException When the transition is not an allowed business path.
     */
    public function transition(AffiliateProfile $profile, string $to): AffiliateProfile
    {
        return DB::transaction(function () use ($profile, $to) {
            $profile = AffiliateProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();

            // The caller's model may have been read before another transition
            // committed. Only the locked, persisted status can authorize this
            // transition.
            if (! in_array($to, self::ALLOWED_TRANSITIONS[$profile->status] ?? [], true)) {
                throw new \DomainException('Transisi status affiliate tidak valid.');
            }

            $profile->update([
                'status' => $to,
                'approved_at' => $to === 'active' ? ($profile->approved_at ?? now()) : $profile->approved_at,
                'approved_by' => $to === 'active' ? ($profile->approved_by ?? auth()->id()) : $profile->approved_by,
            ]);
            $profile->user()->update(['role' => $to === 'active' ? 'affiliate' : 'customer']);
            return $profile->fresh();
        });
    }

    public function reapply(AffiliateProfile $profile, array $data): AffiliateProfile
    {
        if ($profile->status !== AffiliateProfile::STATUS_REJECTED) {
            throw new \DomainException('Profil affiliate tidak dapat mendaftar ulang.');
        }
        return DB::transaction(function () use ($profile, $data) {
            $profile->update(array_merge($data, ['status' => AffiliateProfile::STATUS_PENDING, 'approved_at' => null, 'approved_by' => null]));
            $profile->user()->update(['role' => 'affiliate']);
            return $profile->fresh();
        });
    }
}
