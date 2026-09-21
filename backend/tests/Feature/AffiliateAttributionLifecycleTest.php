<?php

namespace Tests\Feature;

use App\Models\AffiliateClick;
use App\Models\AffiliateProfile;
use App\Models\Order;
use App\Models\User;
use App\Services\AffiliateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AffiliateAttributionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_capture_accepts_active_referral_and_deduplicates_by_visitor_and_affiliate(): void
    {
        $user = User::factory()->create();
        $profile = AffiliateProfile::factory()->forUser($user)->create([
            'status' => AffiliateProfile::STATUS_ACTIVE,
            'referral_code' => 'ACTIVE123',
        ]);

        $first = $this->postJson('/api/affiliate/track', [
            'referral_code' => 'ACTIVE123',
            'visitor_token' => 'visitor-one',
            'landing_url' => 'https://shop.test/?ref=ACTIVE123',
        ]);
        $second = $this->postJson('/api/affiliate/track', [
            'referral_code' => 'ACTIVE123',
            'visitor_token' => 'visitor-one',
        ]);

        $first->assertOk()->assertJsonPath('valid', true);
        $second->assertOk()->assertJsonPath('click_created', false);
        $this->assertSame(1, AffiliateClick::where('affiliate_id', $user->id)->count());
        $this->assertSame($profile->user_id, AffiliateClick::first()->affiliate_id);
    }

    public function test_click_after_dedupe_window_is_accepted_again(): void
    {
        $user = User::factory()->create();
        $profile = AffiliateProfile::factory()->forUser($user)->create([
            'status' => AffiliateProfile::STATUS_ACTIVE,
            'referral_code' => 'ACTIVE123',
        ]);

        $service = app(AffiliateService::class);
        $service->trackReferral('ACTIVE123', 'visitor-one', ['ip_address' => '127.0.0.1']);
        DB::table('affiliate_click_dedupe_states')
            ->where('affiliate_id', $profile->user_id)
            ->where('visitor_token', 'visitor-one')
            ->update(['last_clicked_at' => now()->subMinutes(31)]);

        $result = $service->trackReferral('ACTIVE123', 'visitor-one', ['ip_address' => '127.0.0.1']);

        $this->assertTrue($result['click_created']);
        $this->assertSame(2, AffiliateClick::where('affiliate_id', $user->id)->count());
    }

    public function test_invalid_referral_does_not_create_click_or_replace_valid_attribution(): void
    {
        $user = User::factory()->create();
        AffiliateProfile::factory()->forUser($user)->create([
            'status' => AffiliateProfile::STATUS_ACTIVE,
            'referral_code' => 'VALID123',
        ]);

        $response = $this->postJson('/api/affiliate/track', [
            'referral_code' => 'NOPE',
            'visitor_token' => 'visitor-one',
        ]);

        $response->assertOk()->assertJsonPath('valid', false);
        $this->assertDatabaseCount('affiliate_clicks', 0);
    }

    public function test_dashboard_counts_verified_attributed_orders_not_commissions(): void
    {
        $user = User::factory()->create();
        $profile = AffiliateProfile::factory()->forUser($user)->create(['status' => AffiliateProfile::STATUS_ACTIVE]);
        DB::table('orders')->insert([
            'order_number' => 'ORD-PENDING', 'customer_id' => User::factory()->create()->id,
            'affiliate_id' => $user->id, 'subtotal' => 100, 'commission_amount' => 10,
            'total_amount' => 100, 'status' => Order::STATUS_PENDING, 'shipping_address' => 'test',
            'payment_method' => 'midtrans', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'order_number' => 'ORD-VERIFIED', 'customer_id' => User::factory()->create()->id,
            'affiliate_id' => $user->id, 'subtotal' => 100, 'commission_amount' => 10,
            'total_amount' => 100, 'status' => Order::STATUS_VERIFIED, 'payment_verified_at' => now(),
            'shipping_address' => 'test', 'payment_method' => 'midtrans', 'created_at' => now(), 'updated_at' => now(),
        ]);
        AffiliateClick::create(['affiliate_id' => $user->id, 'referral_code' => $profile->referral_code, 'visitor_token' => 'v', 'ip_address' => '127.0.0.1', 'clicked_at' => now()]);

        $stats = app(AffiliateService::class)->getStats($profile);

        $this->assertSame(1, $stats['total_clicks']);
        $this->assertSame(1, $stats['total_conversions']);
        $this->assertSame(100.0, $stats['conversion_rate']);
    }

    public function test_affiliate_lifecycle_restricts_transitions_and_preserves_code_on_reapply(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $profile = AffiliateProfile::factory()->forUser($user)->create([
            'status' => AffiliateProfile::STATUS_REJECTED,
            'referral_code' => 'KEEP123',
        ]);
        $service = app(AffiliateService::class);

        $service->reapply($profile, ['bank_name' => 'Bank', 'bank_account_number' => '1', 'bank_account_holder' => 'User']);
        $profile->refresh();
        $this->assertSame(AffiliateProfile::STATUS_PENDING, $profile->status);
        $this->assertSame('KEEP123', $profile->referral_code);

        $this->expectException(\DomainException::class);
        $service->transition($profile, AffiliateProfile::STATUS_INACTIVE);
    }

    public function test_lifecycle_uses_locked_persisted_status_not_stale_model(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $profile = AffiliateProfile::factory()->forUser($user)->create([
            'status' => AffiliateProfile::STATUS_PENDING,
        ]);
        $stale = $profile->replicate(['id']);
        $stale->id = $profile->id;
        $profile->update(['status' => AffiliateProfile::STATUS_ACTIVE]);

        $this->expectException(\DomainException::class);
        app(AffiliateService::class)->transition($stale, AffiliateProfile::STATUS_REJECTED);
    }
}
