<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramNotification;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Guards the "Pembayaran Dikonfirmasi" Telegram flow that backs both the real
 * Midtrans settlement webhook and the Filament "Simulasi Webhook Settlement"
 * button — both go through OrderService::verifyPayment().
 */
class PaymentConfirmedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makePendingOrder(): Order
    {
        $customer = User::factory()->create([
            'telegram_chat_id' => '123456789',
        ]);

        return Order::create([
            'order_number'     => 'TDR-PAY-001',
            'customer_id'      => $customer->id,
            'subtotal'         => 150000,
            'total_amount'     => 150000,
            'status'           => 'pending',
            'shipping_address' => 'Jl. Test No. 1, Jakarta',
        ]);
    }

    public function test_verify_payment_sends_single_payment_confirmed_notification(): void
    {
        Queue::fake();
        $order = $this->makePendingOrder();

        app(OrderService::class)->verifyPayment($order, 'SIMULATED-123');

        $fresh = $order->fresh();

        // Status + payment fields updated by the shared flow.
        $this->assertEquals('verified', $fresh->status);
        $this->assertNotNull($fresh->payment_verified_at);
        $this->assertEquals('SIMULATED-123', $fresh->midtrans_transaction_id);

        // Tracking log written.
        $this->assertDatabaseHas('tracking_logs', [
            'order_id'     => $order->id,
            'status_title' => 'Pembayaran Dikonfirmasi',
        ]);

        // Exactly ONE payment.confirmed notification — the observer maps
        // 'verified' to null, so it must not also fire (no double).
        $logs = NotificationLog::where('order_id', $order->id)
            ->where('message_type', 'payment.confirmed')
            ->get();

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('Pembayaran Dikonfirmasi', $logs->first()->message_content);
        $this->assertEquals('123456789', $logs->first()->recipient);

        // And the Telegram job was queued for delivery.
        Queue::assertPushed(SendTelegramNotification::class, 1);
    }

    public function test_verify_payment_is_idempotent_and_does_not_renotify(): void
    {
        Queue::fake();
        $order = $this->makePendingOrder();
        $service = app(OrderService::class);

        $service->verifyPayment($order, 'SIMULATED-123');
        // Second call (e.g. duplicate webhook) must be a no-op: already verified.
        $service->verifyPayment($order->fresh(), 'SIMULATED-123');

        $this->assertCount(
            1,
            NotificationLog::where('order_id', $order->id)->where('message_type', 'payment.confirmed')->get()
        );
        Queue::assertPushed(SendTelegramNotification::class, 1);
    }
}
