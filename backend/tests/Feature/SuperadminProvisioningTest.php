<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuperadminProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_provisioning_creates_multiple_superadmins_without_disclosing_passwords(): void
    {
        foreach (['first@example.net', 'second@example.org'] as $email) {
            $password = bin2hex(random_bytes(24));
            $this->artisan('superadmin:provision', ['email' => $email, '--name' => 'Operator'])
                ->expectsQuestion('Password (minimum 12 characters)', $password)
                ->expectsQuestion('Confirm password', $password)
                ->doesntExpectOutputToContain($password)
                ->assertSuccessful();
            $user = User::where('email', $email)->firstOrFail();
            $this->assertSame('superadmin', $user->role);
            $this->assertTrue(\Illuminate\Support\Facades\Hash::check($password, $user->password));
            $this->assertNull($user->email_verified_at);
            $this->assertTrue((bool) $user->is_active);
        }
        $this->assertSame(2, User::where('role', 'superadmin')->count());
    }

    public function test_provisioning_promotes_existing_user_and_revokes_credentials(): void
    {
        $user = User::factory()->state(['email_verified_at' => null])->create(['is_active' => false]);
        $token = $user->createToken('existing')->plainTextToken;
        $version = $user->credential_version;
        $this->artisan('superadmin:provision', ['email' => $user->email, '--name' => 'Operator'])
            ->expectsConfirmation("Promote existing account {$user->email} to superadmin and revoke all existing credentials?", 'yes')
            ->assertSuccessful();
        $user->refresh();
        $this->assertSame('superadmin', $user->role);
        $this->assertFalse((bool) $user->is_active);
        $this->assertNull($user->email_verified_at);
        $this->assertSame($version + 1, $user->credential_version);
        $this->assertSame(0, $user->tokens()->count());
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/user')->assertUnauthorized();
    }

    public function test_verified_active_promoted_user_can_access_privileged_path(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->artisan('superadmin:provision', ['email' => $user->email, '--name' => 'Operator'])
            ->expectsConfirmation("Promote existing account {$user->email} to superadmin and revoke all existing credentials?", 'yes')
            ->assertSuccessful();
        $token = $user->fresh()->createToken('new')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/admin/filament-sso')
            ->assertOk();
    }

    public function test_provisioning_rejects_weak_passwords(): void
    {
        $this->artisan('superadmin:provision', ['email' => 'operator@example.net', '--name' => 'Operator'])
            ->expectsQuestion('Password (minimum 12 characters)', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_normal_production_seeding_never_creates_demo_or_privileged_accounts(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('affiliate_profiles', 0);
    }

    public function test_legacy_privileged_seeder_is_disabled_even_when_called_directly(): void
    {
        $this->artisan('db:seed', ['--class' => \Database\Seeders\AdminSeeder::class, '--force' => true])
            ->expectsOutputToContain('superadmin:provision')
            ->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_legacy_password_command_cannot_reset_an_account(): void
    {
        $user = User::factory()->create(['email' => 'admin@tdr.test']);
        $before = $user->fresh()->getAttributes();
        $this->artisan('admin:fix-password')->assertFailed();
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_role_preserves_required_no_default_semantics(): void
    {
        $columns = collect(DB::select('PRAGMA table_info(users)'));
        $this->assertNull($columns->firstWhere('name', 'role')->dflt_value);
    }

    public function test_affiliate_demo_seeder_is_guarded_when_called_directly_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('db:seed', ['--class' => \Database\Seeders\AffiliateSeeder::class, '--force' => true])
            ->expectsOutputToContain('skipped')
            ->doesntExpectOutputToContain('Password:')
            ->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('affiliate_profiles', 0);
    }

    public function test_testing_seeding_can_create_demo_affiliates(): void
    {
        $this->artisan('db:seed', ['--class' => \Database\Seeders\AffiliateSeeder::class, '--force' => true])
            ->assertSuccessful();
        $this->assertSame(10, User::where('role', 'affiliate')->count());
        $this->assertSame(10, DB::table('affiliate_profiles')->where('status', 'active')->count());
    }

    public function test_superadmin_factory_uses_current_privileged_role(): void
    {
        $this->assertSame('superadmin', User::factory()->superadmin()->create()->role);
        $this->assertSame('superadmin', User::factory()->admin()->create()->role);
    }


}
