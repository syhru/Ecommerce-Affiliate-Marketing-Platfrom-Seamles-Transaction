<?php

namespace Tests\Feature;

use App\Jobs\AutoCompleteOrders;
use App\Models\AffiliateCommission;
use App\Models\AffiliateProfile;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * WS-03 — Order & Financial Lifecycle Integrity.
 *
 * Covers PA-F-02 (canonical order status), PA-F-08 (commission earned timing)
 * and PA-F-09 (cancellation reconciliation), including idempotency and
 * notification consistency.
 */
class OrderFinancialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a pending order belonging to an active affiliate, with stock
     * already decremented for the ordered quantity (as OrderService does at
     * creation). Returns [order, product, affiliate profile].
     *
     * @return array{0: Order, 1: Product, 2: AffiliateProfile}
     */
    private function makeAffiliateOrder(float $balance = 0, int $initialStock = 10, int $qty = 2): array
    {
        $customer = User::factory()->create(['telegram_chat_id' => '111111111']);
        $affiliateUser = User::factory()->affiliate()->create(['telegram_chat_id' => '222222222']);
        $profile = AffiliateProfile::factory()->forUser($affiliateUser)->create([
            'status'          => 'active',
            'commission_rate' => 10,
            'balance'         => $balance,
            'total_earned'    => $balance,
        ]);

        $product = Product::create([
            'name'        => 'Test Pump',
            'brand'       => 'TDR',
            'type'        => 'pump',
            'category'    => 'motor',
            'description' => 'A test product',
            'price'       => 75000,
            'stock'       => $initialStock - $qty,
            'is_active'   => true,
        ]);

        $subtotal = 75000 * $qty;

        $order = Order::create([
            'order_number'      => 'TDR-WS03-' . uniqid(),
            'customer_id'       => $customer->id,
            'affiliate_id'      => $affiliateUser->id,
            'subtotal'          => $subtotal,
            'commission_amount' => round($subtotal * 0.10, 2),
            'total_amount'      => $subtotal,
            'status'            => Order::STATUS_PENDING,
            'shipping_address'  => 'Jl. Test No. 1, Jakarta',
        ]);

        OrderItem::create([
            'order_id'          => $order->id,
            'product_id'        => $product->id,
            'product_name'      => $product->name,
            'product_price'     => $product->price,
            'quantity'          => $qty,
            'subtotal'          => $subtotal,
            'affiliate_code'    => $profile->referral_code,
        ]);

        return [$order, $product, $profile];
    }

    // ───────────────────────── AC-01 / AC-02 / AC-03 — payment verification ──

    public function test_payment_verification_makes_order_verified_not_paid(): void
    {
        Queue::fake();
        [$order] = $this->makeAffiliateOrder();

        app(OrderService::class)->verifyPayment($order, 'TXN-SETTLE-001');

        $fresh = $order->fresh();

        // AC-01: canonical status is `verified`, never `paid`.
        $this->assertEquals(Order::STATUS_VERIFIED, $fresh->status);
        $this->assertNotSame('paid', $fresh->status);
        $this->assertNotNull($fresh->payment_verified_at);
        $this->assertSame('TXN-SETTLE-001', $fresh->midtrans_transaction_id);
    }

    public function test_payment_verification_creates_pending_commission(): void
    {
        Queue::fake();
        [$order] = $this->makeAffiliateOrder();

        app(OrderService::class)->verifyPayment($order, 'TXN-SETTLE-001');

        $commission = AffiliateCommission::where('order_id', $order->id)->first();

        // AC-02: commission exists and is pending, not earned.
        $this->assertNotNull($commission);
        $this->assertEquals(AffiliateCommission::STATUS_PENDING, $commission->status);
        $this->assertNull($commission->earned_at);
    }

    public function test_payment_verification_does_not_credit_balance(): void
    {
        Queue::fake();
        [$order, , $profile] = $this->makeAffiliateOrder();

        $before = $profile->fresh();

        app(OrderService::class)->verifyPayment($order, 'TXN-SETTLE-001');

        $after = $profile->fresh();

        // AC-03: no early credit of balance or total_earned.
        $this->assertEquals((float) $before->balance, (float) $after->balance);
        $this->assertEquals((float) $before->total_earned, (float) $after->total_earned);
    }

    public function test_duplicate_payment_verification_is_idempotent(): void
    {
        Queue::fake();
        [$order] = $this->makeAffiliateOrder();

        $service = app(OrderService::class);
        $service->verifyPayment($order, 'TXN-SETTLE-001');
        // Second call (duplicate webhook / retry) must be a no-op.
        $service->verifyPayment($order->fresh(), 'TXN-SETTLE-001');

        // Exactly one commission row, still pending.
        $this->assertCount(1, AffiliateCommission::where('order_id', $order->id)->get());
        $this->assertEquals(
            AffiliateCommission::STATUS_PENDING,
            AffiliateCommission::where('order_id', $order->id)->first()->status
        );

        // Exactly one payment.confirmed notification.
        $this->assertCount(1, NotificationLog::where('order_id', $order->id)
            ->where('message_type', 'payment.confirmed')->get());
    }

    // ───────────────────────── AC-04 / AC-05 / AC-06 — completion ─────────────

    public function test_completion_earns_pending_commission_and_credits_once(): void
    {
        Queue::fake();
        [$order, , $profile] = $this->makeAffiliateOrder();

        $service = app(OrderService::class);
        $service->verifyPayment($order, 'TXN-SETTLE-001');
        $order->update(['status' => Order::STATUS_SHIPPED, 'shipped_at' => now()->subDay()]);

        $balanceBefore = (float) $profile->fresh()->balance;
        $earnedBefore  = (float) $profile->fresh()->total_earned;
        $amount        = (float) AffiliateCommission::where('order_id', $order->id)->first()->amount;

        $service->markCompleted($order);

        $commission = AffiliateCommission::where('order_id', $order->id)->first();

        // AC-04: pending → earned.
        $this->assertEquals(AffiliateCommission::STATUS_EARNED, $commission->status);
        $this->assertNotNull($commission->earned_at);

        // AC-05: balance and total_earned each grew by exactly the commission.
        $this->assertEquals($balanceBefore + $amount, (float) $profile->fresh()->balance);
        $this->assertEquals($earnedBefore + $amount, (float) $profile->fresh()->total_earned);
    }

    public function test_repeated_completion_does_not_double_credit(): void
    {
        Queue::fake();
        [$order, , $profile] = $this->makeAffiliateOrder();

        $service = app(OrderService::class);
        $service->verifyPayment($order, 'TXN-SETTLE-001');
        $order->update(['status' => Order::STATUS_SHIPPED, 'shipped_at' => now()->subDay()]);

        $service->markCompleted($order);
        $balanceAfterFirst = (float) $profile->fresh()->balance;

        // AC-06: re-running completion (job retry / scheduler re-tick) is a no-op.
        $service->markCompleted($order->fresh());

        $this->assertEquals($balanceAfterFirst, (float) $profile->fresh()->balance);
        $this->assertEquals($balanceAfterFirst, (float) $profile->fresh()->total_earned);
    }

    public function test_auto_complete_job_earns_commission_once(): void
    {
        // Note: no Queue::fake() — the job must actually run.
        // Telegram is stubbed so no real API call is attempted.
        $this->mock(\App\Services\TelegramService::class, fn ($mock) => $mock->shouldReceive('sendMessage')->andReturn(true));

        [$order, , $profile] = $this->makeAffiliateOrder();

        $service = app(OrderService::class);
        $service->verifyPayment($order, 'TXN-SETTLE-001');
        $order->update(['status' => Order::STATUS_SHIPPED, 'shipped_at' => now()->subDays(8)]);

        // The scheduled job must funnel through the same lifecycle path.
        dispatch(new AutoCompleteOrders());

        $commission = AffiliateCommission::where('order_id', $order->id)->first();

        $this->assertEquals(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertEquals(AffiliateCommission::STATUS_EARNED, $commission->status);

        $balanceAfterJob = (float) $profile->fresh()->balance;

        // Re-dispatching the job (retry) must not double-credit.
        dispatch(new AutoCompleteOrders());

        $this->assertEquals($balanceAfterJob, (float) $profile->fresh()->balance);
    }

    public function test_advance_status_command_completes_order_through_lifecycle(): void
    {
        // Telegram is stubbed so no real API call is attempted.
        $this->mock(\App\Services\TelegramService::class, fn ($mock) => $mock->shouldReceive('sendMessage')->andReturn(true));

        [$order, , $profile] = $this->makeAffiliateOrder();

        $service = app(OrderService::class);
        $service->verifyPayment($order, 'TXN-SETTLE-001');

        // Simulate the scheduler advancing the order through the pipeline.
        $order->update(['status' => Order::STATUS_SHIPPED, 'shipped_at' => now()->subMinutes(20)]);

        \Illuminate\Support\Facades\Artisan::call('orders:advance-status');

        $commission = AffiliateCommission::where('order_id', $order->id)->first();

        // The command must complete the order through OrderService, so the
        // commission is earned — a bare update would have left it pending.
        $this->assertEquals(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertEquals(AffiliateCommission::STATUS_EARNED, $commission->status);
        $this->assertGreaterThan((float) $order->commission_amount - 0.01, (float) $profile->fresh()->balance);
    }

    public function test_completion_of_order_without_affiliate_creates_no_commission(): void
    {
        Queue::fake();
        $customer = User::factory()->create();

        $order = Order::create([
            'order_number'     => 'TDR-NOAFF-' . uniqid(),
            'customer_id'      => $customer->id,
            'subtotal'         => 150000,
            'total_amount'     => 150000,
            'status'           => Order::STATUS_PENDING,
            'shipping_address' => 'Jl. Test No. 1, Jakarta',
        ]);

        $service = app(OrderService::class);
        $service->verifyPayment($order, 'TXN-NOAFF');
        $order->update(['status' => Order::STATUS_SHIPPED, 'shipped_at' => now()->subDay()]);

        $service->markCompleted($order);

        $this->assertEquals(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertCount(0, AffiliateCommission::where('order_id', $order->id)->get());
    }

    // ─────────────── AC-07..AC-12 — cancellation (PA-F-09) ────────────────────

    public function test_cancellation_restores_stock_once_and_voids_pending_commission(): void
    {
        Queue::fake();
        [$order, $product, $profile] = $this->makeAffiliateOrder();

        $service = app(OrderService::class);
        $service->verifyPayment($order, 'TXN-SETTLE-001');
        $order->update(['status' => Order::STATUS_PROCESSING]);

        $balanceBefore = (float) $profile->fresh()->balance;

        $service->cancelOrder($order, 'Stok habis');

        $fresh = $order->fresh();

        // AC-07: stock restored exactly once (10 - 2 = 8, restored to 10).
        $this->assertEquals(10, $product->fresh()->stock);

        $this->assertEquals(Order::STATUS_CANCELLED, $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame('Stok habis', $fresh->cancellation_reason);

        // AC-08: the pending commission is now cancelled, never earned.
        $commission = AffiliateCommission::where('order_id', $order->id)->first();
        $this->assertEquals(AffiliateCommission::STATUS_CANCELLED, $commission->status);
        $this->assertNotNull($commission->cancelled_at);

        // Balance was never touched — the commission was pending.
        $this->assertEquals($balanceBefore, (float) $profile->fresh()->balance);
    }

    public function test_cancelled_order_cannot_earn_commission_afterwards(): void
    {
        Queue::fake();
        [$order, , $profile] = $this->makeAffiliateOrder();

        $service = app(OrderService::class);
        $service->verifyPayment($order, 'TXN-SETTLE-001');
        $order->update(['status' => Order::STATUS_SHIPPED, 'shipped_at' => now()->subDay()]);

        $service->cancelOrder($order);

        $balanceAfterCancel = (float) $profile->fresh()->balance;

        // AC-08: any later attempt to complete the cancelled order must not credit.
        try {
            $service->markCompleted($order->fresh());
        } catch (\Throwable $e) {
            // Rejection is the correct behaviour; a silent no-op is also fine.
        }

        $this->assertEquals(AffiliateCommission::STATUS_CANCELLED,
            AffiliateCommission::where('order_id', $order->id)->first()->status);
        $this->assertEquals($balanceAfterCancel, (float) $profile->fresh()->balance);
        $this->assertEquals($balanceAfterCancel, (float) $profile->fresh()->total_earned);
    }

    public function test_repeated_cancellation_is_idempotent(): void
    {
        Queue::fake();
        [$order, $product] = $this->makeAffiliateOrder();

        $service = app(OrderService::class);
        $service->verifyPayment($order, 'TXN-SETTLE-001');
        $order->update(['status' => Order::STATUS_PROCESSING]);

        $service->cancelOrder($order);
        // A second cancellation (duplicate webhook, double admin click) is a no-op.
        $service->cancelOrder($order->fresh());

        // AC-09: stock restored once, not twice.
        $this->assertEquals(10, $product->fresh()->stock);

        // Exactly one cancellation tracking log.
        $this->assertCount(1, $order->fresh()->trackingLogs()
            ->where('status_title', 'Pesanan Dibatalkan')->get());
    }

    public function test_completed_order_cannot_be_cancelled(): void
    {
        Queue::fake();
        [$order, $product, $profile] = $this->makeAffiliateOrder();

        $service = app(OrderService::class);
        $service->verifyPayment($order, 'TXN-SETTLE-001');
        $order->update(['status' => Order::STATUS_SHIPPED, 'shipped_at' => now()->subDay()]);
        $service->markCompleted($order);

        $balanceAfterCompletion = (float) $profile->fresh()->balance;

        try {
            $service->cancelOrder($order->fresh());
        } catch (\DomainException $e) {
            $this->assertStringContainsString('tidak dapat dibatalkan', $e->getMessage());
        }

        // AC-10: the completed order is untouched.
        $fresh = $order->fresh();
        $this->assertEquals(Order::STATUS_COMPLETED, $fresh->status);
        $this->assertNull($fresh->cancelled_at);
        $this->assertEquals(8, $product->fresh()->stock); // stock never restored
        $this->assertEquals($balanceAfterCompletion, (float) $profile->fresh()->balance);
    }

    public function test_cancellation_records_tracking_history(): void
    {
        Queue::fake();
        [$order] = $this->makeAffiliateOrder();

        $service = app(OrderService::class);
        $service->verifyPayment($order, 'TXN-SETTLE-001');
        $service->cancelOrder($order, 'Dibatalkan oleh admin');

        // AC-11: a tracking entry was written describing the cancellation.
        $this->assertDatabaseHas('tracking_logs', [
            'order_id'     => $order->id,
            'status_title' => 'Pesanan Dibatalkan',
        ]);
    }

    // ─────────────────── AC-19 — notification consistency ─────────────────────

    public function test_commission_notification_states_it_is_pending_until_completion(): void
    {
        Queue::fake();
        [$order] = $this->makeAffiliateOrder();

        app(OrderService::class)->verifyPayment($order, 'TXN-SETTLE-001');

        $log = NotificationLog::where('order_id', $order->id)
            ->where('message_type', 'affiliate.commission')
            ->first();

        // AC-19: the copy must describe a commission that is not yet credited.
        $this->assertNotNull($log);
        $this->assertStringContainsString('setelah pesanan selesai', $log->message_content);
    }

    public function test_balance_credited_notification_only_on_completion(): void
    {
        Queue::fake();
        [$order] = $this->makeAffiliateOrder();

        $service = app(OrderService::class);

        // Verification alone must not announce a balance credit.
        $service->verifyPayment($order, 'TXN-SETTLE-001');

        $this->assertCount(0, NotificationLog::where('order_id', $order->id)
            ->where('message_type', 'affiliate.balance_credited')->get());

        $order->update(['status' => Order::STATUS_SHIPPED, 'shipped_at' => now()->subDay()]);
        $service->markCompleted($order);

        // Only after the credit has committed does the notification appear.
        $this->assertCount(1, NotificationLog::where('order_id', $order->id)
            ->where('message_type', 'affiliate.balance_credited')->get());
    }

    public function test_cancellation_notification_does_not_claim_refund(): void
    {
        Queue::fake();
        [$order] = $this->makeAffiliateOrder();

        $service = app(OrderService::class);
        $service->verifyPayment($order, 'TXN-SETTLE-001');
        $service->cancelOrder($order);

        $log = NotificationLog::where('order_id', $order->id)
            ->where('message_type', 'order.cancelled')
            ->first();

        // AC-12 / AC-19: the copy must not state a refund has been issued.
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('refund telah dikembalikan', $log->message_content);
        $this->assertStringNotContainsString('dana telah kami kembalikan', $log->message_content);
    }

    public function test_terminal_state_transitions_are_rejected(): void
    {
        Queue::fake();
        [$order] = $this->makeAffiliateOrder();

        $service = app(OrderService::class);
        $service->verifyPayment($order, 'TXN-SETTLE-001');
        $order->update(['status' => Order::STATUS_SHIPPED, 'shipped_at' => now()->subDay()]);

        // cancelled → completed is not a legal transition.
        $service->cancelOrder($order);
        $this->expectException(\DomainException::class);
        $service->markCompleted($order->fresh());
    }
}
