<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuperadminRoleMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_role_migration_quarantines_legacy_admin_and_revokes_credentials(): void
    {
        $path = database_path('migrations/2026_09_08_000001_migrate_admin_role_to_superadmin.php');
        $this->assertFileExists($path);
        $migration = require $path;
        $migration->down();
        $user = User::factory()->create(['role' => 'admin']);
        $user->createToken('legacy');
        DB::table('sessions')->insert([
            'id' => 'legacy-session', 'user_id' => $user->id, 'payload' => 'stale', 'last_activity' => time(),
        ]);
        $customer = User::factory()->create();
        $profileId = DB::table('affiliate_profiles')->insertGetId([
            'user_id' => $customer->id, 'referral_code' => 'MIGRATION-TEST', 'approved_by' => $user->id,
        ]);
        $before = DB::table('users')->where('id', $user->id)->first();
        $migration->up();
        $this->assertDatabaseHas('affiliate_profiles', ['id' => $profileId, 'user_id' => $customer->id, 'approved_by' => $user->id]);
        $this->assertSame('customer', $user->fresh()->role);
        $this->assertSame(2, $user->fresh()->credential_version);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'legacy-session']);
        $this->assertSame('customer', $customer->fresh()->role);
        $after = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotSame($before->remember_token, $after->remember_token);
        $before->role = 'customer';
        $before->credential_version = 2;
        $before->remember_token = $after->remember_token;
        $this->assertEquals($before, $after);
        $migration->down();
        $this->assertSame('customer', $user->fresh()->role);
        $migration->up();
    }

    public function test_role_migration_preserves_no_default_semantics(): void
    {
        $columns = collect(DB::select('PRAGMA table_info(users)'));
        $this->assertNull($columns->firstWhere('name', 'role')->dflt_value);
    }
}
