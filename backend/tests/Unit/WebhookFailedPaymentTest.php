<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\MidtransService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class WebhookFailedPaymentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Make MidtransService::isValidSignature always pass so the tests can focus
     * on status handling rather than signature math.
     */
    private function fakeValidSignature(): void
    {
        $this->mock(MidtransService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isValidSignature')->andReturn(true);
        });
    }

    /**
     * Create a pending order with one item that decremented stock at creation,
     * mirroring the real OrderService flow. Returns [order, product].
     *
     * @return array{0: Order, 1: Product}
     */
    private function makePendingOrder(int $initialStock = 10, int $qty = 2, string $orderNumber = 'TDR-FAIL-001'): array
    {
        $customer = User::factory()->create();

        // Stock as it stands AFTER the order was placed (already decremented).
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

        $order = Order::create([
            'order_number'     => $orderNumber,
            'customer_id'      => $customer->id,
            'subtotal'         => 75000 * $qty,
            'total_amount'     => 75000 * $qty,
            'status'           => 'pending',
            'shipping_address' => 'Jl. Test No. 1, Jakarta',
        ]);

        OrderItem::create([
            'order_id'      => $order->id,
            'product_id'    => $product->id,
            'product_name'  => $product->name,
            'product_price' => $product->price,
            'quantity'      => $qty,
            'subtotal'      => 75000 * $qty,
        ]);

        return [$order, $product];
    }

    private function webhookPayload(string $status, string $fraudStatus = '', string $orderNumber = 'TDR-FAIL-001'): array
    {
        return [
            'order_id'           => $orderNumber,
            'status_code'        => '202',
            'gross_amount'       => '150000.00',
            'transaction_status' => $status,
            'fraud_status'       => $fraudStatus,
            'transaction_id'     => 'txn-' . $status,
            'signature_key'      => 'mocked',
        ];
    }

    public function test_expire_cancels_order_and_restores_stock(): void
    {
        $this->fakeValidSignature();
        [$order, $product] = $this->makePendingOrder(initialStock: 10, qty: 2);

        $response = $this->postJson('/api/webhooks/midtrans', $this->webhookPayload('expire'));

        $response->assertOk()->assertJson(['message' => 'OK']);

        $fresh = $order->fresh();
        $this->assertEquals('cancelled', $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertStringContainsString('expire', $fresh->cancellation_reason);
        $this->assertEquals(10, $product->fresh()->stock); // 8 + 2 restored
    }

    public function test_deny_cancels_order_and_restores_stock(): void
    {
        $this->fakeValidSignature();
        [$order, $product] = $this->makePendingOrder(initialStock: 10, qty: 2);

        $response = $this->postJson('/api/webhooks/midtrans', $this->webhookPayload('deny'));

        $response->assertOk();
        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_cancel_cancels_order_and_restores_stock(): void
    {
        $this->fakeValidSignature();
        [$order, $product] = $this->makePendingOrder(initialStock: 10, qty: 2);

        $response = $this->postJson('/api/webhooks/midtrans', $this->webhookPayload('cancel'));

        $response->assertOk();
        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_capture_with_fraud_deny_cancels_order_and_restores_stock(): void
    {
        $this->fakeValidSignature();
        [$order, $product] = $this->makePendingOrder(initialStock: 10, qty: 2);

        $response = $this->postJson('/api/webhooks/midtrans', $this->webhookPayload('capture', 'deny'));

        $response->assertOk();
        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_pending_is_noop_and_keeps_stock(): void
    {
        $this->fakeValidSignature();
        [$order, $product] = $this->makePendingOrder(initialStock: 10, qty: 2);

        $response = $this->postJson('/api/webhooks/midtrans', $this->webhookPayload('pending'));

        $response->assertOk()->assertJson(['message' => 'Ignored.']);
        $this->assertEquals('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->cancelled_at);
        $this->assertEquals(8, $product->fresh()->stock); // unchanged
    }

    public function test_duplicate_failed_webhook_restores_stock_only_once(): void
    {
        $this->fakeValidSignature();
        [$order, $product] = $this->makePendingOrder(initialStock: 10, qty: 2);

        $this->postJson('/api/webhooks/midtrans', $this->webhookPayload('expire'))->assertOk();
        $this->postJson('/api/webhooks/midtrans', $this->webhookPayload('expire'))->assertOk();

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(10, $product->fresh()->stock); // restored once, not 12
    }

    public function test_verified_order_is_not_cancelled_by_failed_webhook(): void
    {
        $this->fakeValidSignature();
        [$order, $product] = $this->makePendingOrder(initialStock: 10, qty: 2);

        // Simulate an already-paid order.
        $order->update([
            'status'                  => 'verified',
            'payment_verified_at'     => now(),
            'midtrans_transaction_id' => 'txn-paid',
        ]);

        $this->postJson('/api/webhooks/midtrans', $this->webhookPayload('deny'))->assertOk();

        $fresh = $order->fresh();
        $this->assertEquals('verified', $fresh->status);
        $this->assertNull($fresh->cancelled_at);
        $this->assertEquals(8, $product->fresh()->stock); // never restored
    }
}
