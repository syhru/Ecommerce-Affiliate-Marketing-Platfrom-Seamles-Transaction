<?php

namespace Tests\PostgreSQL;

use App\Models\AffiliateCommission;
use App\Models\AffiliateProfile;
use App\Models\AffiliateWithdrawal;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\TrackingLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\PostgreSQL\Support\Ownership;
use Tests\PostgreSQL\Support\ProcessHarness;

require_once __DIR__.'/Support/Ownership.php';
require_once __DIR__.'/Support/ProcessHarness.php';

final class InvariantConcurrencyTest extends TestCase
{
    private $app;
    private static bool $migrated = false;

    protected function setUp(): void
    {
        $this->app = Ownership::boot();
        if (! self::$migrated) {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql']));
            foreach (['users' => ['role', 'credential_version'], 'products' => ['stock'],
                'orders' => ['completed_at', 'cancelled_at'], 'affiliate_commissions' => ['cancelled_at'],
                'affiliate_profiles' => ['user_id', 'balance', 'total_earned'],
                'affiliate_withdrawals' => ['affiliate_id', 'amount', 'status', 'processed_at', 'rejection_reason']] as $table => $columns) {
                $this->assertTrue(Schema::hasColumns($table, $columns), $table);
            }
            $expected = array_map(static fn ($file) => basename($file, '.php'), glob(dirname(__DIR__, 2).'/database/migrations/*.php'));
            sort($expected);
            $this->assertSame($expected, DB::table('migrations')->orderBy('migration')->pluck('migration')->all());
            $checks = DB::select("select pg_get_constraintdef(oid) as definition from pg_constraint where contype = 'c' and conrelid in ('orders'::regclass, 'affiliate_commissions'::regclass, 'users'::regclass)");
            $text = implode(' ', array_column($checks, 'definition'));
            $this->assertStringContainsString('superadmin', $text);
            $this->assertStringContainsString('cancelled', $text);
            $this->assertStringNotContainsString("'paid'", $text);
            $this->assertStringNotContainsString("'void'", $text);
            self::$migrated = true;
            $m = Ownership::manifest();
            fwrite(STDOUT, json_encode(['migration_count' => count($expected), 'php' => PHP_VERSION,
                'postgresql_major' => 18, 'isolation' => 'read committed', 'container_id' => $m['container_id'],
                'run_id' => $m['run_id']], JSON_THROW_ON_ERROR).PHP_EOL);
        }
    }

    protected function tearDown(): void
    {
        DB::purge();
        $this->app->flush();
        \Illuminate\Foundation\Bootstrap\HandleExceptions::flushState($this);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Mockery::close();
    }

    private function affiliate(float $balance): AffiliateProfile
    {
        $user = User::factory()->affiliate()->create();
        return AffiliateProfile::factory()->forUser($user)->create([
            'balance' => $balance, 'total_earned' => $balance, 'status' => 'active',
        ]);
    }

    private function product(int $stock): Product
    {
        return Product::create(['name' => 'Fixture Pump '.bin2hex(random_bytes(4)), 'brand' => 'TDR', 'type' => 'pump',
            'category' => 'motor', 'description' => 'Owned disposable fixture', 'price' => 75000,
            'stock' => $stock, 'is_active' => true]);
    }

    private function report(array $evidence, array $persisted): void
    {
        $this->assertTrue($evidence['blocker_observed']);
        $evidence['persisted'] = $persisted;
        fwrite(STDOUT, json_encode($evidence, JSON_THROW_ON_ERROR).PHP_EOL);
    }

    // This first test is deliberately the foundational actual-service schedule.
    public function test_S3_withdrawal_reservation_waits_then_rejects_fresh_pending_state(): void
    {
        $profile = $this->affiliate(500000);
        $evidence = (new ProcessHarness)->run('S3', ['profile' => $profile->id, 'affiliate' => $profile->user_id]);
        $this->assertSame('success', $evidence['A']['outcome']);
        $this->assertSame('pending-rejected', $evidence['B']['outcome']);
        $rows = AffiliateWithdrawal::where('affiliate_id', $profile->user_id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('pending', $rows->first()->status);
        $this->assertEquals(100000, $rows->first()->amount);
        $this->assertEquals(400000, $profile->fresh()->balance);
        $this->assertEquals(500000, $profile->fresh()->total_earned);
        $this->report($evidence, ['pending' => 1, 'amount' => 100000, 'balance' => 400000, 'total_earned' => 500000]);
    }

    public function test_S1_last_stock_checkout_has_only_one_creation_path(): void
    {
        $product = $this->product(1);
        $a = User::factory()->create();
        $b = User::factory()->create();
        $evidence = (new ProcessHarness)->run('S1', ['product' => $product->id, 'buyerA' => $a->id, 'buyerB' => $b->id]);
        $this->assertSame('success', $evidence['A']['outcome']);
        $this->assertSame('stock-rejected', $evidence['B']['outcome']);
        $this->assertSame(1, $evidence['A']['provider_calls']);
        $this->assertSame(0, $evidence['B']['provider_calls']);
        $orders = Order::whereIn('customer_id', [$a->id, $b->id])->get();
        $this->assertCount(1, $orders);
        $order = $orders->first();
        $this->assertSame($a->id, $order->customer_id);
        $this->assertSame('pending', $order->status);
        $this->assertSame('https://example.test/fake', $order->midtrans_snap_token);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertCount(1, $order->items);
        $this->assertSame(1, $order->items->first()->quantity);
        $this->assertSame(1, OrderItem::where('product_id', $product->id)->count());
        $this->assertSame(1, TrackingLog::whereIn('order_id', $orders->modelKeys())->count());
        $this->assertSame('Pesanan Dibuat', $order->trackingLogs->first()->status_title);
        $this->report($evidence, ['stock' => 0, 'orders' => 1, 'items' => 1, 'creation_tracking' => 1, 'loser_orders' => 0]);
    }

    public static function orderSchedules(): array
    {
        return [['S2a', 'completed', 'terminal-rejected', 8, 15000, 'earned'],
            ['S2b', 'cancelled', 'terminal-rejected', 10, 0, 'cancelled'],
            ['S2c', 'completed', 'success', 8, 15000, 'earned']];
    }

    #[DataProvider('orderSchedules')]
    public function test_order_terminal_serialization(string $scenario, string $status, string $loser, int $stock, int $balance, string $commissionStatus): void
    {
        $profile = $this->affiliate(0);
        $product = $this->product(8);
        $customer = User::factory()->create();
        $order = Order::create(['order_number' => 'TDR-'.$scenario.'-'.bin2hex(random_bytes(4)),
            'customer_id' => $customer->id, 'affiliate_id' => $profile->user_id, 'subtotal' => 150000,
            'commission_amount' => 15000, 'total_amount' => 150000, 'status' => 'shipped',
            'payment_verified_at' => now(), 'shipped_at' => now(), 'shipping_address' => 'Fixture address']);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'product_price' => 75000, 'quantity' => 2, 'subtotal' => 150000, 'affiliate_code' => $profile->referral_code]);
        $commission = AffiliateCommission::create(['order_id' => $order->id, 'affiliate_id' => $profile->user_id,
            'amount' => 15000, 'commission_rate' => 10, 'status' => 'pending']);
        $evidence = (new ProcessHarness)->run($scenario, ['order' => $order->id]);
        $this->assertSame('success', $evidence['A']['outcome']);
        $this->assertSame($loser, $evidence['B']['outcome']);
        $fresh = $order->fresh();
        $this->assertSame($status, $fresh->status);
        $this->assertEquals($stock, $product->fresh()->stock);
        $this->assertEquals($balance, $profile->fresh()->balance);
        $this->assertEquals($balance, $profile->fresh()->total_earned);
        $this->assertSame($commissionStatus, $commission->fresh()->status);
        $this->assertSame(1, AffiliateCommission::where('order_id', $order->id)->count());
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
        $this->assertSame(1, Order::whereKey($order->id)->count());
        if ($status === 'completed') {
            $this->assertNotNull($fresh->completed_at);
            $this->assertNull($fresh->cancelled_at);
            $this->assertNull($fresh->cancellation_reason);
            $this->assertNotNull($commission->fresh()->earned_at);
            $this->assertNull($commission->fresh()->cancelled_at);
            $this->assertSame(0, TrackingLog::where('order_id', $order->id)->where('status_title', 'Pesanan Dibatalkan')->count());
            $this->assertSame($evidence['A']['terminal_at'], $fresh->completed_at->toISOString());
        } else {
            $this->assertNotNull($fresh->cancelled_at);
            $this->assertNull($fresh->completed_at);
            $this->assertSame('worker-A', $fresh->cancellation_reason);
            $this->assertNull($commission->fresh()->earned_at);
            $this->assertNotNull($commission->fresh()->cancelled_at);
            $this->assertSame(1, TrackingLog::where('order_id', $order->id)->where('status_title', 'Pesanan Dibatalkan')->count());
        }
        $this->report($evidence, ['order' => $status, 'commission' => $commissionStatus, 'stock' => $stock,
            'balance' => $balance, 'total_earned' => $balance, 'commission_rows' => 1]);
    }

    public static function withdrawalSchedules(): array
    {
        return [['S4a', 'rejected', 500000], ['S4b', 'completed', 350000], ['S4c', 'rejected', 500000]];
    }

    #[DataProvider('withdrawalSchedules')]
    public function test_withdrawal_terminal_metadata_and_refund_once(string $scenario, string $status, int $balance): void
    {
        $profile = $this->affiliate(500000);
        $withdrawal = app(\App\Services\AffiliateService::class)->processWithdrawal($profile, 150000, [
            'bank_name' => 'TEST', 'bank_account_number' => '000000', 'bank_account_holder' => 'Fixture']);
        $this->assertEquals(350000, $profile->fresh()->balance);
        $a = User::factory()->superadmin()->create(['email_verified_at' => now()]);
        $b = User::factory()->superadmin()->create(['email_verified_at' => now()]);
        $evidence = (new ProcessHarness)->run($scenario, ['withdrawal' => $withdrawal->id, 'adminA' => $a->id, 'adminB' => $b->id]);
        $this->assertSame('success', $evidence['A']['outcome']);
        $this->assertSame('success', $evidence['B']['outcome']);
        $fresh = $withdrawal->fresh();
        $this->assertSame($status, $fresh->status);
        $this->assertEquals($balance, $profile->fresh()->balance);
        $this->assertEquals(500000, $profile->fresh()->total_earned);
        $this->assertSame($a->id, $fresh->processed_by);
        $this->assertNotNull($fresh->processed_at);
        $this->assertSame($evidence['A']['terminal_at'], $fresh->processed_at->toISOString());
        $this->assertSame($status === 'rejected' ? 'worker-A' : null, $fresh->rejection_reason);
        $this->assertSame(1, AffiliateWithdrawal::where('affiliate_id', $profile->user_id)->count());
        $this->report($evidence, ['withdrawal' => $status, 'balance' => $balance, 'total_earned' => 500000,
            'rows' => 1, 'first_metadata_retained' => true]);
        // These peers must not weaken the exactly-two-admin S5 fixture.
        $a->delete();
        $b->delete();
    }

    public function test_S5_profile_kernel_waiter_preserves_last_eligible_admin_atomically(): void
    {
        $this->assertSame(0, User::where('role', 'superadmin')->count());
        $a = User::factory()->superadmin()->create(['email_verified_at' => now()]);
        $b = User::factory()->superadmin()->create(['email_verified_at' => now()]);
        $original = $b->fresh()->getAttributes();
        $epoch = $a->credential_version;
        $evidence = (new ProcessHarness)->run('S5', ['userA' => $a->id, 'userB' => $b->id]);
        $this->assertSame('success', $evidence['A']['outcome']);
        $this->assertSame('profile-rejected', $evidence['B']['outcome']);
        $this->assertSame(422, $evidence['B']['http_status']);
        $this->assertTrue($evidence['B']['email_validation']);
        $this->assertTrue($evidence['B']['no_notification']);
        $this->assertSame($original, $b->fresh()->getAttributes());
        $this->assertTrue($b->fresh()->is_active);
        $this->assertTrue($b->fresh()->hasVerifiedEmail());
        $this->assertFalse($a->fresh()->is_active);
        $this->assertSame($epoch + 1, $a->fresh()->credential_version);
        $this->assertSame(1, User::where('role', 'superadmin')->where('is_active', true)->whereNotNull('email_verified_at')->count());
        $this->report($evidence, ['eligible_superadmins' => 1, 'U1_inactive' => true, 'U2_unchanged' => true,
            'verification_notifications' => 0]);
    }
}
