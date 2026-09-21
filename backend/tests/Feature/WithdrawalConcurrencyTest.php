<?php

namespace Tests\Feature;

use App\Models\AffiliateProfile;
use App\Models\AffiliateWithdrawal;
use App\Models\User;
use App\Services\AffiliateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WS-03 / R3-F-03 — the single-pending-withdrawal rule must survive real
 * concurrency.
 *
 * The previous implementation ran `lockForUpdate()->exists()` over the pending
 * withdrawal set. With zero pending rows that query locks nothing, so two
 * concurrent requests both passed the check and both inserted. The fix
 * serializes requests on a `lockForUpdate()` row lock over the canonical
 * AffiliateProfile row, acquired before the pending check, the deduction and
 * the insert, and held for the whole transaction.
 *
 * Note on drivers: SQLite compiles `lockForUpdate()` to an empty string — it
 * is a no-op there — so a SQLite suite cannot exercise row-lock contention.
 * The authoritative proof for this finding is the two-connection PostgreSQL
 * probe recorded in the remediation result file. What this file asserts on
 * SQLite is the shape of the fixed path the probe exercises: the profile row
 * is the locked canonical object, the pending check reads committed state
 * under it, and the serial path still enforces one pending withdrawal with a
 * single deduction.
 */
class WithdrawalConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_withdrawal_locks_the_canonical_profile_row(): void
    {
        $user = User::factory()->affiliate()->create(['telegram_chat_id' => '444444444']);
        DB::table('users')->where('id', $user->id)->update(['email_verified_at' => now()]);

        $profile = AffiliateProfile::factory()->forUser($user)->create([
            'status'       => 'active',
            'balance'      => 500000,
            'total_earned' => 500000,
        ]);

        $captured = [];

        DB::enableQueryLog();

        app(AffiliateService::class)->processWithdrawal(
            $profile->fresh(),
            100000,
            [
                'bank_name'           => 'BCA',
                'bank_account_number' => '1234567890',
                'bank_account_holder' => 'TEST USER',
            ]
        );

        // The profile lock must be taken before the pending check, so the
        // deduction and the insert run under it.
        foreach (DB::getQueryLog() as $query) {
            $captured[] = $query['query'];
        }

        $profileLockIndex = null;
        $pendingCheckIndex = null;

        foreach ($captured as $index => $sql) {
            if (str_contains($sql, 'from "affiliate_profiles"') && $profileLockIndex === null) {
                $profileLockIndex = $index;
            }

            if (str_contains($sql, 'from "affiliate_withdrawals"') && $pendingCheckIndex === null) {
                $pendingCheckIndex = $index;
            }
        }

        $this->assertNotNull($profileLockIndex, 'processWithdrawal must read the canonical AffiliateProfile row.');
        $this->assertNotNull($pendingCheckIndex, 'processWithdrawal must check the pending withdrawal set.');
        $this->assertLessThan(
            $pendingCheckIndex,
            $profileLockIndex,
            'The AffiliateProfile row must be read (and locked on PostgreSQL) before the pending withdrawal check.'
        );

        $this->assertCount(1, AffiliateWithdrawal::all());
        $this->assertEquals(400000, (float) $profile->fresh()->balance);
    }

    public function test_serial_requests_still_enforce_the_single_pending_rule(): void
    {
        $user = User::factory()->affiliate()->create(['telegram_chat_id' => '555555555']);
        DB::table('users')->where('id', $user->id)->update(['email_verified_at' => now()]);

        $profile = AffiliateProfile::factory()->forUser($user)->create([
            'status'       => 'active',
            'balance'      => 500000,
            'total_earned' => 500000,
        ]);

        $service = app(AffiliateService::class);

        $bankData = [
            'bank_name'           => 'BCA',
            'bank_account_number' => '1234567890',
            'bank_account_holder' => 'TEST USER',
        ];

        $first = $service->processWithdrawal($profile->fresh(), 150000, $bankData);

        $this->assertSame(AffiliateWithdrawal::STATUS_PENDING, $first->status);
        $this->assertEquals(350000, (float) $profile->fresh()->balance);

        // The pending row is now visible to the second request — under the
        // profile lock on PostgreSQL, and through the committed read here.
        try {
            $service->processWithdrawal($profile->fresh(), 100000, $bankData);
            $this->fail('A second pending withdrawal must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('sedang diproses', $e->getMessage());
        }

        // Exactly one pending withdrawal, deducted exactly once.
        $this->assertCount(1, AffiliateWithdrawal::where('affiliate_id', $user->id)->get());
        $this->assertEquals(350000, (float) $profile->fresh()->balance);
    }
}
