<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileEmailAdminSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): static
    {
        Auth::forgetGuards();
        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function eligibleCount(): int
    {
        return User::where('role', 'superadmin')->where('is_active', true)
            ->whereNotNull('email_verified_at')->count();
    }

    public function test_inactive_and_unverified_peers_cannot_enable_profile_email_change(): void
    {
        Notification::fake();
        $admin = User::factory()->superadmin()->create(['email_verified_at' => now()]);
        User::factory()->superadmin()->create(['is_active' => false, 'email_verified_at' => now()]);
        User::factory()->superadmin()->create(['email_verified_at' => null]);
        $original = $admin->fresh()->getAttributes();

        $this->bearer($admin)->putJson('/api/user/profile', [
            'email' => 'changed@example.test', 'current_password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame($original, $admin->fresh()->getAttributes());
        $this->assertSame(1, $this->eligibleCount());
        Notification::assertNothingSent();
    }

    public function test_profile_email_change_is_allowed_with_another_eligible_admin(): void
    {
        Notification::fake();
        $admin = User::factory()->superadmin()->create(['email_verified_at' => now()]);
        $other = User::factory()->superadmin()->create(['email_verified_at' => now()]);
        $peerState = $other->fresh()->getAttributes();
        $epoch = $admin->credentialEpoch();

        $this->bearer($admin)->putJson('/api/user/profile', [
            'email' => 'changed@example.test', 'current_password' => 'password',
        ])->assertOk()->assertJsonPath('user.email', 'changed@example.test')
            ->assertJsonPath('user.email_verified', false);

        $fresh = $admin->fresh();
        $this->assertSame('changed@example.test', $fresh->email);
        $this->assertNull($fresh->email_verified_at);
        $this->assertSame('superadmin', $fresh->role);
        $this->assertSame($epoch, $fresh->credentialEpoch());
        $this->assertSame(1, $fresh->tokens()->count());
        $this->assertSame($peerState, $other->fresh()->getAttributes());
        $this->assertSame(1, $this->eligibleCount());
        \Illuminate\Support\Facades\Notification::assertSentToTimes($fresh, \Illuminate\Auth\Notifications\VerifyEmail::class, 1);
        Notification::assertNotSentTo($other, \Illuminate\Auth\Notifications\VerifyEmail::class);
    }

    public function test_customer_profile_email_change_preserves_password_and_verification_flow(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $original = $user->fresh()->getAttributes();
        $this->bearer($user)->putJson('/api/user/profile', ['email' => 'changed@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        Auth::forgetGuards();
        $this->putJson('/api/user/profile', [
            'email' => 'changed@example.test', 'current_password' => 'wrong',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame($original, $user->fresh()->getAttributes());
        Notification::assertNothingSent();

        Auth::forgetGuards();
        $this->putJson('/api/user/profile', [
            'email' => 'changed@example.test', 'current_password' => 'password',
        ])->assertOk();
        $fresh = $user->fresh();
        $this->assertSame('changed@example.test', $fresh->email);
        $this->assertNull($fresh->email_verified_at);
        $this->assertSame('customer', $fresh->role);
        Notification::assertSentToTimes($fresh, \Illuminate\Auth\Notifications\VerifyEmail::class, 1);
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
            'id' => $user->id, 'hash' => sha1($fresh->email),
        ]);
        $this->get($url)->assertRedirect('http://localhost:3000/email-verified?status=success');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_customer_and_affiliate_profile_payloads_cannot_persist_privileged_fields(): void
    {
        Notification::fake();
        foreach (['customer', 'affiliate'] as $role) {
            $user = User::factory()->create(['role' => $role, 'email_verified_at' => now()]);
            $epoch = $user->credentialEpoch();
            $this->bearer($user)->putJson('/api/user/profile', [
                'name' => 'Updated name', 'email' => $role.'@example.test',
                'telegram_chat_id' => '12345', 'current_password' => 'password',
                'role' => 'superadmin', 'is_active' => false, 'password' => 'forged-password',
                'email_verified_at' => now()->toDateTimeString(), 'credential_version' => 999,
            ])->assertOk();
            $fresh = $user->fresh();
            $this->assertSame($role, $fresh->role);
            $this->assertSame('Updated name', $fresh->name);
            $this->assertSame('12345', $fresh->telegram_chat_id);
            $this->assertSame($role.'@example.test', $fresh->email);
            $this->assertNull($fresh->email_verified_at);
            $this->assertTrue($fresh->is_active);
            $this->assertSame($epoch, $fresh->credentialEpoch());
            $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password', $fresh->password));
            Notification::assertSentToTimes($fresh, \Illuminate\Auth\Notifications\VerifyEmail::class, 1);
        }
        $this->assertSame(0, $this->eligibleCount());
    }

    public function test_sole_eligible_admin_profile_email_change_is_atomic_and_preserves_panel_access(): void
    {
        Notification::fake();
        $admin = User::factory()->superadmin()->create(['email_verified_at' => now()]);
        $original = $admin->fresh()->getAttributes();
        $token = $admin->createToken('test')->plainTextToken;
        Auth::forgetGuards();

        $this->withToken($token)->putJson('/api/user/profile', [
            'name' => 'Must not persist',
            'email' => 'changed@example.test',
            'current_password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $fresh = $admin->fresh();
        $this->assertSame($original, $fresh->getAttributes());
        $this->assertSame(1, User::where('role', 'superadmin')->where('is_active', true)
            ->whereNotNull('email_verified_at')->count());
        $this->assertTrue($fresh->canAccessPanel(Filament::getPanel('admin')));
        Notification::assertNothingSent();
        Auth::forgetGuards();
        $this->actingAs($fresh, 'web')->withSession(['credential_epoch' => $fresh->credentialEpoch()])
            ->get('/admin/users')->assertOk();
    }
}
