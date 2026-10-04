<?php

namespace Tests\Feature;

use App\Models\AffiliateProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminSignedTransportTest extends TestCase
{
    use RefreshDatabase;

    private function snapshot(User $admin, User $target): string
    {
        $this->actingAs($admin)->withSession(['credential_epoch' => $admin->credentialEpoch(), 'password_hash_web' => $admin->password]);
        $html = $this->get('/admin/users/'.$target->id.'/edit')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', $html, $matches));
        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function save(string $snapshot): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-Livewire', 'true')->postJson('/livewire/update', ['components' => [[
            'snapshot' => $snapshot,
            'updates' => ['data.name' => 'Signed transport edit'],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]]]);
    }

    public function test_signed_affiliate_save_cannot_rewrite_money_or_owner(): void
    {
        Queue::fake();
        $admin = User::factory()->superadmin()->create(['email_verified_at' => now()]);
        $profile = AffiliateProfile::factory()->create(['balance' => 200000, 'total_earned' => 300000]);
        $other = User::factory()->create();
        $this->actingAs($admin)->withSession(['credential_epoch' => $admin->credentialEpoch(), 'password_hash_web' => $admin->password]);
        $html = $this->get('/admin/affiliates/'.$profile->id.'/edit')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', $html, $matches));
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->withHeader('X-Livewire', 'true')->postJson('/livewire/update', ['components' => [[
            'snapshot' => $snapshot,
            'updates' => ['data.balance' => 999999, 'data.total_earned' => 999999, 'data.user_id' => $other->id, 'data.bank_name' => 'Updated bank'],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]]])->assertOk();
        $fresh = $profile->fresh();
        $this->assertEquals(200000, $fresh->balance);
        $this->assertEquals(300000, $fresh->total_earned);
        $this->assertSame($profile->user_id, $fresh->user_id);
        $this->assertSame('Updated bank', $fresh->bank_name);
    }

    public function test_signed_withdrawal_save_preserves_reservation_and_refunds_original_amount(): void
    {
        Queue::fake();
        $admin = User::factory()->superadmin()->create(['email_verified_at' => now()]);
        $profile = AffiliateProfile::factory()->create(['balance' => 200000]);
        $other = User::factory()->create();
        $service = app(\App\Services\AffiliateService::class);
        $bank = ['bank_name' => 'Original', 'bank_account_number' => '123', 'bank_account_holder' => 'Holder'];
        $withdrawal = $service->processWithdrawal($profile, 100000, $bank);
        $this->actingAs($admin)->withSession(['credential_epoch' => $admin->credentialEpoch(), 'password_hash_web' => $admin->password]);
        $html = $this->get('/admin/withdrawals/'.$withdrawal->id.'/edit')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', $html, $matches));
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->withHeader('X-Livewire', 'true')->postJson('/livewire/update', ['components' => [[
            'snapshot' => $snapshot,
            'updates' => ['data.amount' => 200000, 'data.affiliate_id' => $other->id, 'data.bank_name' => 'Forged', 'data.bank_account_number' => '999', 'data.bank_account_holder' => 'Forged', 'data.notes' => 'Reviewed'],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]]])->assertOk();
        $fresh = $withdrawal->fresh();
        $this->assertEquals(100000, $fresh->amount);
        $this->assertSame($profile->user_id, $fresh->affiliate_id);
        foreach ($bank as $key => $value) {
            $this->assertSame($value, $fresh->$key);
        }
        $this->assertSame('Reviewed', $fresh->notes);
        $service->rejectWithdrawal($fresh, 'Rejected', $admin->id);
        $service->rejectWithdrawal($fresh, 'Repeated', $admin->id);
        $this->assertEquals(200000, $profile->fresh()->balance);
    }

    public function test_signed_admin_mutation_denies_every_ineligible_actor_and_allows_fresh_admin(): void
    {
        Queue::fake();
        $admin = User::factory()->superadmin()->create(['email_verified_at' => now()]);
        $target = User::factory()->create();
        $originalName = $target->name;
        $snapshot = $this->snapshot($admin, $target);
        $actors = [['guest', null, 401], ['customer', User::factory()->create(), 403]];
        foreach (AffiliateProfile::STATUSES as $status) {
            $user = User::factory()->affiliate()->create(['email_verified_at' => now()]);
            AffiliateProfile::factory()->forUser($user)->create(['status' => $status]);
            $actors[] = ['affiliate '.$status, $user, 403];
        }
        $actors[] = ['inactive', User::factory()->superadmin()->create(['is_active' => false, 'email_verified_at' => now()]), 403];
        $actors[] = ['unverified', User::factory()->superadmin()->create(), 403];
        $actors[] = ['stale', $admin, 401];

        foreach ($actors as [$label, $actor, $status]) {
            Auth::logout();
            Auth::forgetGuards();
            if ($actor) {
                $this->actingAs($actor);
            }
            $this->withSession(['credential_epoch' => $label === 'stale' ? '0' : ($actor?->credentialEpoch() ?? '')]);
            $response = $this->save($snapshot);
            $this->assertSame($status, $response->getStatusCode(), $label);
            $this->assertSame($originalName, $target->fresh()->name, $label);
        }

        Auth::forgetGuards();
        $this->actingAs($admin)->withSession(['credential_epoch' => $admin->credentialEpoch(), 'password_hash_web' => $admin->password]);
        $response = $this->save($snapshot);
        $response->assertOk();
        $this->assertSame('Signed transport edit', $target->fresh()->name);
    }
}
