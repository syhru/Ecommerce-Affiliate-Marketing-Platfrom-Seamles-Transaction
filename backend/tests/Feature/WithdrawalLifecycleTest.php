<?php

namespace Tests\Feature;

use App\Models\AffiliateProfile;
use App\Models\AffiliateWithdrawal;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\AffiliateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * WS-03 — Withdrawal lifecycle integrity (PA-F-10).
 *
 * Covers AC-13 through AC-18: the single-pending rule, atomic deduction,
 * decision operations, and their idempotency.
 */
class WithdrawalLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeAffiliate(float $balance = 500000): array
    {
        $user = User::factory()->affiliate()->create(['telegram_chat_id' => '333333333']);

        // The API withdrawal route is gated behind email verification
        // (WS-01 regression guard, preserved here).
        DB::table('users')->where('id', $user->id)->update(['email_verified_at' => now()]);

        $profile = AffiliateProfile::factory()->forUser($user)->create([
            'status'       => 'active',
            'balance'      => $balance,
            'total_earned' => $balance,
        ]);

        return [$user->fresh(), $profile];
    }

    private function bankData(): array
    {
        return [
            'bank_name'           => 'BCA',
            'bank_account_number' => '1234567890',
            'bank_account_holder' => 'TEST USER',
        ];
    }

    // ───────────────────────── AC-13 / AC-14 — request ─────────────────────────

    public function test_withdrawal_request_deducts_balance_and_creates_pending_row(): void
    {
        Queue::fake();
        [$user, $profile] = $this->makeAffiliate(500000);

        $withdrawal = app(AffiliateService::class)->processWithdrawal($profile->fresh(), 150000, $this->bankData());

        // AC-14: deduction and row creation are one atomic operation.
        $this->assertSame(AffiliateWithdrawal::STATUS_PENDING, $withdrawal->status);
        $this->assertEquals(350000, (float) $profile->fresh()->balance);
        $this->assertDatabaseHas('affiliate_withdrawals', [
            'id'            => $withdrawal->id,
            'affiliate_id'  => $user->id,
            'amount'        => 150000,
            'status'        => AffiliateWithdrawal::STATUS_PENDING,
        ]);
    }

    public function test_second_pending_withdrawal_is_rejected(): void
    {
        Queue::fake();
        [, $profile] = $this->makeAffiliate(500000);

        $service = app(AffiliateService::class);
        $service->processWithdrawal($profile->fresh(), 150000, $this->bankData());

        // AC-13: only one pending withdrawal per affiliate.
        $this->expectException(\InvalidArgumentException::class);
        $service->processWithdrawal($profile->fresh(), 100000, $this->bankData());

        // The rejected attempt must not have deducted anything more.
        $this->assertEquals(350000, (float) $profile->fresh()->balance);
    }

    public function test_insufficient_balance_is_rejected(): void
    {
        Queue::fake();
        [, $profile] = $this->makeAffiliate(50000);

        $service = app(AffiliateService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->processWithdrawal($profile->fresh(), 60000, $this->bankData());

        $this->assertEquals(50000, (float) $profile->fresh()->balance);
        $this->assertCount(0, AffiliateWithdrawal::all());
    }

    public function test_withdrawal_endpoint_enforces_single_pending_rule(): void
    {
        Queue::fake();
        [$user, $profile] = $this->makeAffiliate(500000);

        // Seed an existing pending withdrawal directly.
        AffiliateWithdrawal::create([
            'affiliate_id'        => $user->id,
            'amount'              => 100000,
            'status'              => AffiliateWithdrawal::STATUS_PENDING,
            ...$this->bankData(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/affiliate/withdraw', array_merge([
                'amount' => 100000,
            ], $this->bankData()))
            ->assertStatus(422);

        // The API must not have created a second pending withdrawal.
        $this->assertCount(1, AffiliateWithdrawal::where('affiliate_id', $user->id)->get());
        $this->assertEquals(500000, (float) $profile->fresh()->balance);
    }

    // ───────────────── AC-15 / AC-16 / AC-17 / AC-18 — decisions ───────────────

    public function test_completing_a_withdrawal_does_not_deduct_again(): void
    {
        Queue::fake();
        [$user, $profile] = $this->makeAffiliate(500000);

        $service = app(AffiliateService::class);
        $withdrawal = $service->processWithdrawal($profile->fresh(), 150000, $this->bankData());

        $service->completeWithdrawal($withdrawal, $user->id);

        $fresh = $withdrawal->fresh();

        // AC-15: completed, no second deduction.
        $this->assertSame(AffiliateWithdrawal::STATUS_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->processed_at);
        $this->assertSame($user->id, $fresh->processed_by);
        $this->assertEquals(350000, (float) $profile->fresh()->balance);
    }

    public function test_rejecting_a_withdrawal_refunds_balance(): void
    {
        Queue::fake();
        [$user, $profile] = $this->makeAffiliate(500000);

        $service = app(AffiliateService::class);
        $withdrawal = $service->processWithdrawal($profile->fresh(), 150000, $this->bankData());

        $service->rejectWithdrawal($withdrawal, 'Data rekening tidak valid', $user->id);

        $fresh = $withdrawal->fresh();

        // AC-16: balance restored.
        $this->assertSame(AffiliateWithdrawal::STATUS_REJECTED, $fresh->status);
        $this->assertSame('Data rekening tidak valid', $fresh->rejection_reason);
        $this->assertNotNull($fresh->processed_at);
        $this->assertEquals(500000, (float) $profile->fresh()->balance);
    }

    public function test_repeated_rejection_does_not_double_refund(): void
    {
        Queue::fake();
        [$user, $profile] = $this->makeAffiliate(500000);

        $service = app(AffiliateService::class);
        $withdrawal = $service->processWithdrawal($profile->fresh(), 150000, $this->bankData());

        $service->rejectWithdrawal($withdrawal, 'Data rekening tidak valid', $user->id);
        $service->rejectWithdrawal($withdrawal->fresh(), 'ulangi', $user->id);

        // AC-17: refunded exactly once.
        $this->assertEquals(500000, (float) $profile->fresh()->balance);
    }

    public function test_completed_withdrawal_cannot_be_rejected(): void
    {
        Queue::fake();
        [$user, $profile] = $this->makeAffiliate(500000);

        $service = app(AffiliateService::class);
        $withdrawal = $service->processWithdrawal($profile->fresh(), 150000, $this->bankData());

        $service->completeWithdrawal($withdrawal, $user->id);

        // AC-18: a completed withdrawal is terminal, so the decision operations
        // must not touch it. The refund path can never run for it.
        $service->rejectWithdrawal($withdrawal->fresh(), 'terlambat', $user->id);

        $this->assertSame(AffiliateWithdrawal::STATUS_COMPLETED, $withdrawal->fresh()->status);
        $this->assertEquals(350000, (float) $profile->fresh()->balance);
    }

    public function test_rejected_withdrawal_cannot_be_completed(): void
    {
        Queue::fake();
        [$user, $profile] = $this->makeAffiliate(500000);

        $service = app(AffiliateService::class);
        $withdrawal = $service->processWithdrawal($profile->fresh(), 150000, $this->bankData());

        $service->rejectWithdrawal($withdrawal, 'Data rekening tidak valid', $user->id);

        // AC-18 (second direction): a rejected withdrawal is terminal. The
        // refund was already applied, so completing it afterwards must not
        // remove the refund from the balance.
        $service->completeWithdrawal($withdrawal->fresh(), $user->id);

        $this->assertSame(AffiliateWithdrawal::STATUS_REJECTED, $withdrawal->fresh()->status);
        $this->assertSame('Data rekening tidak valid', $withdrawal->fresh()->rejection_reason);
        $this->assertEquals(500000, (float) $profile->fresh()->balance);
    }

    // ───────────────────── AC-19 — notification consistency ────────────────────

    public function test_rejection_notification_only_says_refunded_after_refund(): void
    {
        Queue::fake();
        [$user, $profile] = $this->makeAffiliate(500000);

        $service = app(AffiliateService::class);
        $withdrawal = $service->processWithdrawal($profile->fresh(), 150000, $this->bankData());

        // Before any decision, no rejection notification may exist.
        $this->assertCount(0, NotificationLog::where('user_id', $user->id)
            ->where('message_type', 'affiliate.withdrawal_rejected')->get());

        $service->rejectWithdrawal($withdrawal, 'Data rekening tidak valid', $user->id);

        $log = NotificationLog::where('user_id', $user->id)
            ->where('message_type', 'affiliate.withdrawal_rejected')
            ->first();

        // The notification may only appear after the refund committed, and it
        // must describe a balance that has actually been returned.
        $this->assertNotNull($log);
        $this->assertStringContainsString('Saldo Anda telah dikembalikan', $log->message_content);
        $this->assertEquals(500000, (float) $profile->fresh()->balance);
    }

    public function test_withdrawal_request_sends_pending_notification_after_commit(): void
    {
        Queue::fake();
        [$user, $profile] = $this->makeAffiliate(500000);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/affiliate/withdraw', array_merge(['amount' => 150000], $this->bankData()))
            ->assertStatus(201);

        // AC-19: the request notification exists only because the row and the
        // deduction both committed.
        $this->assertDatabaseHas('notification_logs', [
            'user_id'      => $user->id,
            'message_type' => 'affiliate.withdrawal',
            'order_id'     => null,
        ]);

        $withdrawal = AffiliateWithdrawal::where('affiliate_id', $user->id)->first();

        $this->assertSame(AffiliateWithdrawal::STATUS_PENDING, $withdrawal->status);
        $this->assertEquals(350000, (float) $profile->fresh()->balance);
    }
}
