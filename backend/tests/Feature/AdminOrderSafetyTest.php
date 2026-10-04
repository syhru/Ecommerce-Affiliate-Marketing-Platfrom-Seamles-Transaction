<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AdminOrderSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->actingAs(User::factory()->create([
            'role' => 'superadmin', 'is_active' => true, 'email_verified_at' => now(),
        ]));
    }

    private function order(string $status): Order
    {
        return Order::create([
            'order_number' => 'WS06-' . uniqid(),
            'customer_id' => User::factory()->create()->id,
            'subtotal' => 10000, 'total_amount' => 10000,
            'status' => $status, 'shipping_address' => 'Test address',
        ]);
    }

    public function test_production_simulation_is_hidden_and_forged_callback_is_denied(): void
    {
        $this->app['env'] = 'production';
        $order = $this->order(Order::STATUS_PENDING);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->id]);
        $page->assertActionHidden('simulasi_pembayaran');
        $page->instance()->getAction('simulasi_pembayaran')->call();
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertNull($order->fresh()->payment_verified_at);
        $this->assertSame(0, $order->trackingLogs()->count());
    }

    public function test_nonproduction_simulation_uses_real_verification(): void
    {
        $order = $this->order(Order::STATUS_PENDING);
        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->callAction('simulasi_pembayaran')->assertNotified('Simulasi pembayaran berhasil diproses.');
        $this->assertSame(Order::STATUS_VERIFIED, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->payment_verified_at);
        $this->assertStringStartsWith('SIMULATED-', $order->fresh()->midtrans_transaction_id);
        $this->assertSame(1, $order->trackingLogs()->count());
    }

    public function test_simulation_service_rejects_production(): void
    {
        $this->app['env'] = 'production';
        $order = $this->order(Order::STATUS_PENDING);
        try {
            app(OrderService::class)->simulatePayment($order);
            $this->fail('Production simulation must be rejected.');
        } catch (\DomainException $exception) {
            $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
            $this->assertNull($order->fresh()->payment_verified_at);
        }
    }

    public function test_stale_simulation_callback_cannot_verify_cancelled_order(): void
    {
        $order = $this->order(Order::STATUS_PENDING);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->id]);
        $order->update(['status' => Order::STATUS_CANCELLED]);
        $page->instance()->getAction('simulasi_pembayaran')->call();
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertNull($order->fresh()->payment_verified_at);
        $this->assertSame(0, $order->trackingLogs()->count());
    }

    public function test_arbitrary_event_is_validation_error(): void
    {
        $order = $this->order(Order::STATUS_VERIFIED);
        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->callAction('update_status', ['event' => 'forged.event'])
            ->assertHasActionErrors(['event']);
        $this->assertSame(Order::STATUS_VERIFIED, $order->fresh()->status);
        $this->assertSame(0, $order->trackingLogs()->count());
    }

    public function test_forged_invalid_completion_callback_is_controlled(): void
    {
        $order = $this->order(Order::STATUS_PROCESSING);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->id]);
        $page->instance()->getAction('update_status')->formData(['event' => 'order.delivered'])->call();
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
        $this->assertNull($order->fresh()->completed_at);
    }

    public function test_stale_completion_request_is_controlled(): void
    {
        $order = $this->order(Order::STATUS_SHIPPED);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->id]);
        $page->mountAction('update_status');
        $order->update(['status' => Order::STATUS_CANCELLED]);
        $page->setActionData(['event' => 'order.delivered'])->callMountedAction();
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_livewire_forward_edges_write_tracking_only_on_shipping(): void
    {
        $order = $this->order(Order::STATUS_VERIFIED);
        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->callAction('update_status', ['event' => 'order.processing', 'resi' => 'FORGED-PROCESSING'])
            ->assertHasNoActionErrors();
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
        $this->assertNull($order->fresh()->shipping_tracking_number);
        $this->assertNull($order->fresh()->shipped_at);
        $this->assertSame(1, $order->trackingLogs()->count());

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->callAction('update_status', ['event' => 'order.shipped', 'resi' => 'VALID-RESI'])
            ->assertHasNoActionErrors();
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        $this->assertSame('VALID-RESI', $order->fresh()->shipping_tracking_number);
        $this->assertNotNull($order->fresh()->shipped_at);
        $this->assertSame(2, $order->trackingLogs()->count());
    }

    public function test_stale_fulfilment_callback_cannot_overwrite_newer_state(): void
    {
        foreach ([Order::STATUS_SHIPPED, Order::STATUS_COMPLETED, Order::STATUS_CANCELLED] as $current) {
            $order = $this->order(Order::STATUS_VERIFIED);
            $page = Livewire::test(ViewOrder::class, ['record' => $order->id]);
            $page->mountAction('update_status');
            $order->update(['status' => $current, 'shipping_tracking_number' => 'KEEP']);
            $page->setActionData(['event' => 'order.processing', 'resi' => 'FORGED'])->callMountedAction();
            // Bypass presentation visibility as well: the callback must still enforce persisted state.
            $page->instance()->getAction('update_status')
                ->formData(['event' => 'order.processing', 'resi' => 'FORGED'])->call();
            $this->assertSame($current, $order->fresh()->status);
            $this->assertSame('KEEP', $order->fresh()->shipping_tracking_number);
            $this->assertSame(0, $order->trackingLogs()->count());
        }
    }

    public function test_invalid_shipping_does_not_write_tracking_or_timestamp(): void
    {
        $order = $this->order(Order::STATUS_VERIFIED);
        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->callAction('update_status', ['event' => 'order.shipped', 'resi' => 'FORGED'])
            ->assertNotified('Status pesanan tidak dapat diperbarui.');
        $this->assertSame(Order::STATUS_VERIFIED, $order->fresh()->status);
        $this->assertNull($order->fresh()->shipping_tracking_number);
        $this->assertNull($order->fresh()->shipped_at);
        $this->assertSame(0, $order->trackingLogs()->count());
    }

    public function test_callback_rejects_missing_and_arbitrary_events(): void
    {
        $order = $this->order(Order::STATUS_VERIFIED);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->id]);
        foreach ([[], ['event' => 'forged.event']] as $data) {
            $page->instance()->getAction('update_status')->formData($data)->call();
            $this->assertSame(Order::STATUS_VERIFIED, $order->fresh()->status);
            $this->assertSame(0, $order->trackingLogs()->count());
        }
    }

    public function test_cancel_callback_on_completed_order_is_controlled(): void
    {
        $order = $this->order(Order::STATUS_COMPLETED);
        $page = Livewire::test(ViewOrder::class, ['record' => $order->id]);
        $page->instance()->getAction('update_status')->formData(['event' => 'order.cancelled'])->call();
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertNull($order->fresh()->cancelled_at);
    }

    public function test_fulfilment_service_uses_persisted_state_and_ignores_processing_tracking(): void
    {
        $order = $this->order(Order::STATUS_VERIFIED);
        app(OrderService::class)->advanceFulfilment($order, 'order.processing', 'IGNORED');
        $this->assertNull($order->fresh()->shipping_tracking_number);
        app(OrderService::class)->advanceFulfilment($order, 'order.shipped', 'VALID');
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        $this->assertSame('VALID', $order->fresh()->shipping_tracking_number);
        try {
            app(OrderService::class)->advanceFulfilment($order, 'forged.event', 'FORGED');
            $this->fail('Invalid event must be rejected.');
        } catch (\DomainException $exception) {
            $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
            $this->assertSame('VALID', $order->fresh()->shipping_tracking_number);
            $this->assertSame(2, $order->trackingLogs()->count());
        }
    }

    public function test_livewire_reverse_edge_is_rejected_without_mutation(): void
    {
        $order = $this->order(Order::STATUS_SHIPPED);
        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->callAction('update_status', ['event' => 'order.processing'])
            ->assertNotified('Status pesanan tidak dapat diperbarui.');
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        $this->assertSame(0, $order->trackingLogs()->count());
    }
}
