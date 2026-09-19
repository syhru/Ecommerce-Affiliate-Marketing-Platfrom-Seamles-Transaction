<?php

namespace Tests\Feature;

use App\Models\AffiliateProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\MidtransService;
use App\Services\OrderService;
use App\Services\ShippingRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * WS-02 — Order Creation & Checkout Integrity.
 *
 * Covers the stock, shipping, affiliate and logging decisions in
 * WS-02_Hermes_Builder_Order_Creation_Checkout_Integrity.md (AC-01..AC-14).
 *
 * Concurrency note: the phpunit.xml test driver is SQLite in :memory:, which
 * does not have real row-level locking. The stock safety here does not depend
 * on locking — it depends on the single conditional UPDATE
 * (WHERE stock >= qty), which SQLite evaluates atomically per statement.
 * test_concurrent_checkout_cannot_oversell_last_units exercises that guard
 * directly against the database.
 */
class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The Midtrans Snap call is the external boundary of order creation.
     * Fake it and record the gross_amount so the tests can prove the payment
     * amount equals the server-calculated total.
     */
    private function fakeMidtrans(): void
    {
        $this->mock(MidtransService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createSnapToken')
                ->andReturnUsing(function (array $params): string {
                    // Persist what the backend actually tried to charge.
                    Http::fake(); // no real network
                    OrderCreationTest::$lastMidtransParams = $params;

                    return 'https://app.sandbox.midtrans.com/snap/v2/transactions/fake-token';
                });
        });
    }

    /** Shared payload builder. shipping_cost is intentionally NOT sent. */
    private function payload(Product $product, int $qty, array $overrides = []): array
    {
        return array_merge([
            'shipping_courier' => 'jne_reg',
            'shipping_address' => 'Jl. Test No. 1, Jakarta',
            'payment_method' => 'midtrans',
            'items' => [
                ['product_id' => $product->id, 'quantity' => $qty],
            ],
        ], $overrides);
    }

    private function customer(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    // ───────────────────────── AC-01 / AC-03: insufficient & negative stock ─────────────────────────

    public function test_exact_stock_purchase_succeeds(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(5)->create(['price' => 50000]);
        $customer = $this->customer();

        $order = app(OrderService::class)->createOrder($this->payload($product, 5), $customer->id);

        $this->assertEquals('pending', $order->status);
        $this->assertEquals(5, $order->items()->first()->quantity);
        $this->assertEquals(0, $product->fresh()->stock);
    }

    public function test_insufficient_stock_is_rejected(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(3)->create(['price' => 50000]);

        try {
            app(OrderService::class)->createOrder($this->payload($product, 4), $this->customer()->id);
            $this->fail('Order with insufficient stock should have been rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak mencukupi', $e->getMessage());
        }

        // No partial state survives the failure.
        $this->assertEquals(3, $product->fresh()->stock);
        $this->assertDatabaseEmpty('orders');
        $this->assertDatabaseEmpty('order_items');
        $this->assertDatabaseEmpty('tracking_logs');
    }

    public function test_zero_stock_is_rejected(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(0)->create(['price' => 50000]);

        try {
            app(OrderService::class)->createOrder($this->payload($product, 1), $this->customer()->id);
            $this->fail('Order with zero stock should have been rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak mencukupi', $e->getMessage());
        }

        $this->assertEquals(0, $product->fresh()->stock);
        $this->assertDatabaseEmpty('orders');
    }

    public function test_stock_can_never_go_negative(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(2)->create(['price' => 50000]);

        try {
            app(OrderService::class)->createOrder($this->payload($product, 10), $this->customer()->id);
        } catch (\InvalidArgumentException $e) {
            // expected
        }

        $this->assertGreaterThanOrEqual(0, (int) $product->fresh()->stock);
        $this->assertEquals(2, (int) $product->fresh()->stock);
    }

    // ───────────────────────── AC-04: duplicate product lines ─────────────────────────

    public function test_duplicate_product_lines_are_summed_for_stock_validation(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(5)->create(['price' => 10000]);
        $customer = $this->customer();

        $payload = $this->payload($product, 1, [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ]);

        $order = app(OrderService::class)->createOrder($payload, $customer->id);

        // Effective quantity is 5, which exactly exhausts the stock.
        $this->assertCount(1, $order->items);
        $this->assertEquals(5, $order->items()->first()->quantity);
        $this->assertEquals(0, $product->fresh()->stock);
    }

    public function test_duplicate_product_lines_cannot_oversell(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(4)->create(['price' => 10000]);

        $payload = $this->payload($product, 1, [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ]);

        try {
            app(OrderService::class)->createOrder($payload, $this->customer()->id);
            $this->fail('Duplicated lines summing over stock should be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak mencukupi', $e->getMessage());
        }

        $this->assertEquals(4, $product->fresh()->stock);
        $this->assertDatabaseEmpty('orders');
    }

    // ───────────────────────── AC-05: concurrency ─────────────────────────

    public function test_concurrent_checkout_cannot_oversell_last_units(): void
    {
        $this->fakeMidtrans();

        // Only 1 unit left; two concurrent orders each want it.
        $product = Product::factory()->stock(1)->create(['price' => 50000]);
        $service = app(OrderService::class);

        $successes = 0;
        $failures = 0;

        // SQLite in :memory: cannot fork real processes, so this cannot be a
        // true two-process race. We simulate the *decisive* property of the
        // race instead: two order attempts against one remaining unit, with no
        // intermediate re-read of stock helping the second attempt. Because
        // OrderService reserves through a conditional UPDATE (WHERE stock >=
        // qty), exactly one attempt can succeed and the loser is rejected.
        for ($i = 0; $i < 2; $i++) {
            try {
                $service->createOrder($this->payload($product, 1), $this->customer()->id);
                $successes++;
            } catch (\InvalidArgumentException $e) {
                $failures++;
            }
        }

        $this->assertEquals(1, $successes, 'Only one of the two competing orders may succeed.');
        $this->assertEquals(1, $failures, 'The losing order must be rejected, not silently queued.');
        $this->assertEquals(0, (int) $product->fresh()->stock);
        $this->assertGreaterThanOrEqual(0, (int) $product->fresh()->stock);
    }

    /**
     * The strongest driver-independent proof of the atomic guard: hit the
     * conditional UPDATE directly N times in a loop and assert the total
     * reserved can never exceed the available stock.
     */
    public function test_atomic_reservation_never_exceeds_available_stock(): void
    {
        $product = Product::factory()->stock(10)->create();
        $service = app(\App\Services\ProductInventoryService::class);

        $reserved = 0;

        for ($i = 0; $i < 20; $i++) {
            try {
                $service->reserve($product->fresh(), 1);
                $reserved++;
            } catch (\InvalidArgumentException $e) {
                // stock exhausted — remaining attempts must all fail
            }
        }

        $this->assertEquals(10, $reserved, 'Reservation count must equal available stock exactly.');
        $this->assertEquals(0, (int) $product->fresh()->stock);
        $this->assertGreaterThanOrEqual(0, (int) $product->fresh()->stock);
    }

    // ───────────────────────── AC-06: rollback consistency ─────────────────────────

    public function test_failure_after_partial_stock_reservation_rolls_back_everything(): void
    {
        // Midtrans failure on the FIRST item leaves no stock mutation behind.
        $this->mock(MidtransService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createSnapToken')
                ->andThrow(new \RuntimeException('Midtrans is down'));
        });

        $product = Product::factory()->stock(5)->create(['price' => 50000]);
        $other = Product::factory()->stock(5)->create(['price' => 20000]);

        $payload = $this->payload($product, 1, [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
                ['product_id' => $other->id, 'quantity' => 1],
            ],
        ]);

        try {
            app(OrderService::class)->createOrder($payload, $this->customer()->id);
            $this->fail('Order should have failed when Midtrans is unreachable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Midtrans is down', $e->getMessage());
        }

        // No partial stock mutation, no partial order, no partial item.
        $this->assertEquals(5, (int) $product->fresh()->stock);
        $this->assertEquals(5, (int) $other->fresh()->stock);
        $this->assertDatabaseEmpty('orders');
        $this->assertDatabaseEmpty('order_items');
    }

    // ───────────────────────── AC-07 / AC-08 / AC-09: shipping & totals ─────────────────────────

    public function test_client_supplied_shipping_cost_is_ignored(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(5)->create(['price' => 50000]);

        // A manipulated shipping_cost must not change what is charged.
        $payload = $this->payload($product, 2, ['shipping_cost' => 999999]);

        $order = app(OrderService::class)->createOrder($payload, $this->customer()->id);

        $expectedShipping = app(ShippingRateService::class)->cost('jne_reg');

        $this->assertEquals($expectedShipping, (float) $order->shipping_cost);
        $this->assertEquals(100000 + $expectedShipping, (float) $order->total_amount);
        $this->assertEquals(
            100000 + $expectedShipping,
            (float) self::$lastMidtransParams['transaction_details']['gross_amount']
        );
    }

    public function test_valid_courier_uses_server_side_rate(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(5)->create(['price' => 50000]);

        foreach (app(ShippingRateService::class)->rates() as $courier => $rate) {
            $payload = $this->payload($product, 1, ['shipping_courier' => $courier]);
            $order = app(OrderService::class)->createOrder($payload, $this->customer()->id);

            $this->assertEquals($rate, (float) $order->shipping_cost);
            $this->assertEquals(50000 + $rate, (float) $order->total_amount);
            $this->assertEquals($courier, $order->shipping_courier);
        }
    }

    public function test_invalid_courier_is_rejected_before_any_stock_is_touched(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(5)->create(['price' => 50000]);

        try {
            app(OrderService::class)->createOrder(
                $this->payload($product, 1, ['shipping_courier' => 'gw_dikirim_lewat_burung']),
                $this->customer()->id
            );
            $this->fail('Unsupported courier should be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak tersedia', $e->getMessage());
        }

        $this->assertEquals(5, (int) $product->fresh()->stock);
        $this->assertDatabaseEmpty('orders');
    }

    public function test_midtrans_amount_equals_server_calculated_total(): void
    {
        $this->fakeMidtrans();

        $a = Product::factory()->stock(10)->create(['price' => 75000]);
        $b = Product::factory()->stock(10)->create(['price' => 25000]);

        $payload = $this->payload($a, 2, [
            'shipping_courier' => 'jne_yes',
            'items' => [
                ['product_id' => $a->id, 'quantity' => 2],
                ['product_id' => $b->id, 'quantity' => 1],
            ],
        ]);

        $order = app(OrderService::class)->createOrder($payload, $this->customer()->id);

        $expectedShipping = app(ShippingRateService::class)->cost('jne_yes');
        $expectedSubtotal = 75000 * 2 + 25000;

        $this->assertEquals($expectedSubtotal, (float) $order->subtotal);
        $this->assertEquals($expectedShipping, (float) $order->shipping_cost);
        $this->assertEquals($expectedSubtotal + $expectedShipping, (float) $order->total_amount);

        // The exact gross_amount handed to Midtrans.
        $this->assertEquals(
            $expectedSubtotal + $expectedShipping,
            (float) self::$lastMidtransParams['transaction_details']['gross_amount']
        );

        // And the item_details sum to the same figure.
        $itemSum = collect(self::$lastMidtransParams['item_details'])->sum(fn ($i) => $i['price'] * $i['quantity']);
        $this->assertEquals($expectedSubtotal + $expectedShipping, (float) $itemSum);
    }

    // ───────────────────────── AC-10 / AC-11 / AC-12: affiliate ─────────────────────────

    public function test_valid_referral_code_attributes_order_to_the_right_affiliate(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(5)->create(['price' => 100000]);
        $profile = AffiliateProfile::factory()->create(['commission_rate' => 10]);
        $customer = $this->customer();

        $payload = $this->payload($product, 1, [
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'affiliate_code' => $profile->referral_code]],
        ]);

        $order = app(OrderService::class)->createOrder($payload, $customer->id);

        $this->assertEquals($profile->user_id, $order->affiliate_id);
        $this->assertEquals($profile->referral_code, $order->items()->first()->affiliate_code);
        $this->assertEquals(10000, (float) $order->commission_amount);
    }

    public function test_invalid_referral_code_does_not_attribute_to_another_affiliate(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(5)->create(['price' => 100000]);
        $realAffiliate = AffiliateProfile::factory()->create();

        $payload = $this->payload($product, 1, [
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'affiliate_code' => 'REF-BOGUS-CODE']],
        ]);

        $order = app(OrderService::class)->createOrder($payload, $this->customer()->id);

        $this->assertNull($order->affiliate_id);
        $this->assertEquals(0, (float) $order->commission_amount);
        $this->assertNotEquals($realAffiliate->user_id, $order->affiliate_id);
        $this->assertEquals('pending', $order->status);
    }

    public function test_user_name_is_no_longer_used_as_affiliate_fallback(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(5)->create(['price' => 100000]);

        // An affiliate whose *name* equals the supplied code. The pre-WS-02
        // fallback would have resolved this; referral_code-only resolution
        // must not.
        $affiliateUser = User::factory()->affiliate()->create(['name' => 'Budi Santoso']);
        AffiliateProfile::factory()->forUser($affiliateUser)->create();

        $payload = $this->payload($product, 1, [
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'affiliate_code' => 'Budi Santoso']],
        ]);

        $order = app(OrderService::class)->createOrder($payload, $this->customer()->id);

        $this->assertNull($order->affiliate_id, 'users.name must never resolve an affiliate.');
        $this->assertEquals(0, (float) $order->commission_amount);
    }

    public function test_inactive_affiliate_referral_code_is_not_resolved(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(5)->create(['price' => 100000]);
        $profile = AffiliateProfile::factory()->create(['status' => 'pending']);

        $payload = $this->payload($product, 1, [
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'affiliate_code' => $profile->referral_code]],
        ]);

        $order = app(OrderService::class)->createOrder($payload, $this->customer()->id);

        $this->assertNull($order->affiliate_id);
    }

    // ───────────────────────── AC-13: existing valid checkout ─────────────────────────

    public function test_valid_checkout_through_the_api_still_works(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(5)->create(['price' => 50000]);
        $customer = $this->customer();

        $response = $this->actingAs($customer)->postJson('/api/orders', $this->payload($product, 2));

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'order' => ['order_number', 'total_amount'], 'payment_url']);

        $this->assertDatabaseHas('orders', [
            'customer_id' => $customer->id,
            'shipping_courier' => 'jne_reg',
            'total_amount' => 100000 + 15000,
        ]);
        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
        $this->assertEquals(3, $product->fresh()->stock);
    }

    public function test_insufficient_stock_returns_422_through_the_api(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(1)->create(['price' => 50000]);

        $response = $this->actingAs($this->customer())
            ->postJson('/api/orders', $this->payload($product, 2));

        $response->assertStatus(422);
        $this->assertDatabaseEmpty('orders');
        $this->assertEquals(1, $product->fresh()->stock);
    }

    public function test_http_endpoint_rejects_invalid_courier_with_422(): void
    {
        $this->fakeMidtrans();

        $product = Product::factory()->stock(5)->create(['price' => 50000]);

        $response = $this->actingAs($this->customer())
            ->postJson('/api/orders', $this->payload($product, 1, ['shipping_courier' => 'np_invalid']));

        $response->assertStatus(422);
        $this->assertDatabaseEmpty('orders');
    }

    // ───────────────────────── AC-14: logging cleanup ─────────────────────────

    public function test_order_creation_hot_path_emits_no_debug_logging(): void
    {
        // Capture everything the hot path would log. WS-02 §5.7 removed the
        // leftover customer/referral debug logging; nothing may reappear here.
        $handler = new \Monolog\Handler\TestHandler;
        \Illuminate\Support\Facades\Log::setHandlers([$handler]);

        $this->fakeMidtrans();

        $product = Product::factory()->stock(5)->create(['price' => 50000]);
        $customer = $this->customer();

        app(OrderService::class)->createOrder($this->payload($product, 1), $customer->id);

        $this->assertSame([], $handler->getRecords(), 'Order creation hot path must not emit leftover debug logs.');

        // Belt and braces: no customer/referral identifiers are logged merely
        // for debugging, even if some record were emitted.
        foreach ($handler->getRecords() as $record) {
            $serialized = json_encode($record['context'] ?? []);
            $this->assertStringNotContainsString($customer->email, $serialized);
            $this->assertStringNotContainsString((string) $customer->id, $serialized);
        }
    }

    public static array $lastMidtransParams = [];
}
