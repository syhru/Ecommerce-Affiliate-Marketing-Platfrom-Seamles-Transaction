<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IdentityAdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(string $token): static
    {
        Auth::forgetGuards();
        return $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    public function test_login_never_promotes_and_unverified_account_access_is_limited(): void
    {
        Notification::fake();
        $user = User::factory()->state(['email_verified_at' => null])->create(['email' => 'existing@tdr-hpz.com', 'role' => 'customer']);
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->json('token');
        $this->assertSame('customer', $user->fresh()->role);
        $this->bearer($token)->getJson('/api/user')->assertOk();
        $this->bearer($token)->putJson('/api/user/profile', ['name' => 'Updated'])->assertOk();
        $this->bearer($token)->postJson('/api/user/link-telegram', ['telegram_chat_id' => '12345'])->assertOk();
        $this->bearer($token)->postJson('/api/email/verification-notification')->assertOk();
        Notification::assertSentTo($user, VerifyEmail::class);
        foreach (['/api/orders', '/api/admin/filament-sso'] as $url) {
            $this->bearer($token)->postJson($url)->assertForbidden();
        }
        foreach (['dashboard', 'commissions', 'clicks', 'withdrawals'] as $route) {
            $this->bearer($token)->getJson('/api/affiliate/'.$route)->assertForbidden();
        }
    }

    public function test_registration_rejects_superadmin_role(): void
    {
        $this->postJson('/api/register', ['name' => 'Attacker', 'email' => 'a@example.com', 'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'role' => 'superadmin'])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_email_change_requires_password_and_reverification_without_role_change(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => 'customer']);
        $token = $user->createToken('test')->plainTextToken;
        $this->bearer($token)->putJson('/api/user/profile', ['email' => 'new@tdr-hpz.com'])->assertUnprocessable();
        $this->bearer($token)->putJson('/api/user/profile', ['email' => 'new@tdr-hpz.com', 'current_password' => 'wrong'])->assertUnprocessable();
        $this->bearer($token)->putJson('/api/user/profile', ['email' => 'new@tdr-hpz.com', 'current_password' => 'password'])->assertOk();
        $this->assertSame('customer', $user->fresh()->role);
        $this->assertNull($user->fresh()->email_verified_at);
        Notification::assertSentTo($user->fresh(), VerifyEmail::class);
        $this->bearer($token)->postJson('/api/orders')->assertForbidden();
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), ['id' => $user->id, 'hash' => sha1('new@tdr-hpz.com')]);
        $this->get($url)->assertRedirect('http://localhost:3000/email-verified?status=success');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_user_resource_exposes_only_boolean_verification_state(): void
    {
        $user = User::factory()->state(['email_verified_at' => null])->create();
        $token = $user->createToken('test')->plainTextToken;
        $this->bearer($token)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonMissingPath('data.email_verified_at');
    }

    public function test_normal_profile_update_does_not_rotate_credential_version(): void
    {
        $user = User::factory()->create();
        $version = $user->credential_version;
        $user->update(['name' => 'Updated name']);
        $this->assertSame($version, $user->fresh()->credential_version);
    }

    public function test_role_change_rotates_version_and_revokes_tokens(): void
    {
        $user = User::factory()->create();
        $version = $user->credential_version;
        $user->createToken('old');
        $user->update(['role' => 'affiliate']);
        $this->assertSame($version + 1, $user->fresh()->credential_version);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_deactivation_revokes_tokens_and_reactivation_does_not_restore_them(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $this->bearer($token)->getJson('/api/user')->assertOk();
        $user->update(['is_active' => false]);
        $this->assertSame(0, $user->tokens()->count());
        $this->bearer($token)->getJson('/api/user')->assertUnauthorized();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertForbidden();
        $user->update(['is_active' => true]);
        $this->bearer($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_password_change_revokes_every_token(): void
    {
        $user = User::factory()->create();
        $version = $user->credential_version;
        $rememberToken = $user->remember_token;
        $tokens = [$user->createToken('one')->plainTextToken, $user->createToken('two')->plainTextToken];
        $this->bearer($tokens[0])->putJson('/api/user/password', ['current_password' => 'password', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])->assertOk();
        $this->assertSame($version + 1, $user->fresh()->credential_version);
        $this->assertNotSame($rememberToken, $user->fresh()->remember_token);
        foreach ($tokens as $token) {
            $this->bearer($token)->getJson('/api/user')->assertUnauthorized();
        }
    }

    public function test_password_reset_revokes_every_token(): void
    {
        $user = User::factory()->create();
        $version = $user->credential_version;
        $tokens = [$user->createToken('one')->plainTextToken, $user->createToken('two')->plainTextToken];
        $reset = \Illuminate\Support\Facades\Password::createToken($user);
        $this->postJson('/api/reset-password', ['token' => $reset, 'email' => $user->email, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])->assertOk();
        $this->assertSame($version + 1, $user->fresh()->credential_version);
        foreach ($tokens as $token) {
            $this->bearer($token)->getJson('/api/user')->assertUnauthorized();
        }
    }

    public function test_logout_revokes_only_current_token(): void
    {
        $user = User::factory()->state(['email_verified_at' => null])->create();
        $one = $user->createToken('one')->plainTextToken;
        $two = $user->createToken('two')->plainTextToken;
        $this->bearer($one)->postJson('/api/logout')->assertOk();
        $this->bearer($one)->getJson('/api/user')->assertUnauthorized();
        $this->bearer($two)->getJson('/api/user')->assertOk();
    }

    public function test_sso_requires_active_verified_superadmin_and_is_one_time(): void
    {
        $user = User::factory()->create(['role' => 'superadmin', 'email_verified_at' => now()]);
        $token = $user->createToken('test')->plainTextToken;
        $url = $this->bearer($token)->postJson('/api/admin/filament-sso')->assertOk()->json('url');
        Auth::forgetGuards();
        $this->get($url)->assertRedirect('/admin');
        $this->get($url)->assertRedirect('/admin/login');
    }

    public static function ssoRechecksProvider(): array
    {
        return [
            ['role'],
            ['email_verified_at'],
            ['is_active'],
            ['password'],
            ['reactivate'],
        ];
    }

    /**
     * @dataProvider ssoRechecksProvider
     */
    public function test_sso_rechecks_role_verification_status_and_revocation(string $change): void
    {
        $user = User::factory()->create(['role' => 'superadmin', 'email_verified_at' => now()]);
        $token = $user->createToken('test')->plainTextToken;
        $url = $this->bearer($token)->postJson('/api/admin/filament-sso')->assertOk()->json('url');
        if ($change === 'reactivate') {
            $user->update(['is_active' => false]);
            $user->update(['is_active' => true]);
        } else {
            $value = match ($change) {
                'role' => 'customer', 'email_verified_at' => null, 'is_active' => false, 'password' => 'changed-password',
            };
            $user->forceFill([$change => $value])->save();
        }
        Auth::forgetGuards();
        $this->get($url)->assertRedirect('/admin/login');
    }

    public function test_filament_denies_invalid_accounts_and_stale_sessions(): void
    {
        $panel = \Filament\Facades\Filament::getPanel('admin');
        foreach ([['role' => 'customer'], ['is_active' => false], ['email_verified_at' => null]] as $state) {
            $user = User::factory()->create(array_merge(['role' => 'superadmin', 'email_verified_at' => now()], $state));
            $this->assertFalse($user->canAccessPanel($panel));
            $this->actingAs($user)->get('/admin')->assertForbidden();
        }
        $user = User::factory()->create(['role' => 'superadmin', 'email_verified_at' => now()]);
        $epoch = $user->credentialEpoch();
        $user->update(['is_active' => false]);
        $user->update(['is_active' => true]);
        $this->actingAs($user->fresh())->withSession(['credential_epoch' => $epoch])->get('/admin')->assertUnauthorized();
    }

    public function test_filament_rejects_stale_session_after_password_change_and_reset(): void
    {
        foreach (['change', 'reset'] as $event) {
            $user = User::factory()->create(['role' => 'superadmin', 'email_verified_at' => now()]);
            $epoch = $user->credentialEpoch();
            if ($event === 'reset') {
                $resetToken = \Illuminate\Support\Facades\Password::createToken($user);
                $this->postJson('/api/reset-password', [
                    'token' => $resetToken,
                    'email' => $user->email,
                    'password' => 'ResetPassword123!',
                    'password_confirmation' => 'ResetPassword123!',
                ])->assertOk();
            } else {
                $user->update(['password' => 'ChangedPassword123!']);
            }
            $response = $this->actingAs($user->fresh())
                ->withSession(['credential_user_id' => $user->id, 'credential_epoch' => $epoch])
                ->get('/admin');
            $this->assertContains($response->getStatusCode(), [302, 401]);
            Auth::logout();
        }
    }

    public function test_domain_registration_is_nonprivileged_and_sends_verification(): void
    {
        Notification::fake();
        $this->postJson('/api/register', [
            'name' => 'Customer', 'email' => 'customer@tdr-hpz.com',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
        ])->assertCreated();
        $user = User::where('email', 'customer@tdr-hpz.com')->firstOrFail();
        $this->assertSame('customer', $user->role);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
