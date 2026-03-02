<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\User;
use App\Services\MidtransService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_signature_returns_401(): void
    {
        $payload = [
            'order_id'           => 'TDR-TEST-001',
            'status_code'        => '200',
            'gross_amount'       => '100000.00',
            'transaction_status' => 'settlement',
            'fraud_status'       => 'accept',
            'transaction_id'     => 'txn-test-001',
            'signature_key'      => 'this_is_definitely_not_a_valid_signature',
        ];

        $response = $this->postJson('/api/webhooks/midtrans', $payload);

        $response->assertStatus(403)
                 ->assertJson(['message' => 'Invalid signature.']);
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        $customer = User::factory()->create();
        $order = Order::create([
            'order_number'            => 'TDR-IDEM-001',
            'customer_id'             => $customer->id,
            'subtotal'                => 155000,
            'total_amount'            => 170000,
            'status'                  => 'verified',
            'shipping_address'        => 'Jl. Test No. 1, Jakarta',
            'payment_verified_at'     => now(),
            'midtrans_transaction_id' => 'txn-already-done',
        ]);

        $this->mock(MidtransService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isValidSignature')->once()->andReturn(true);
        });

        $payload = [
            'order_id'           => $order->order_number,
            'status_code'        => '200',
            'gross_amount'       => '170000.00',
            'transaction_status' => 'settlement',
            'fraud_status'       => 'accept',
            'transaction_id'     => 'txn-already-done',
            'signature_key'      => 'mocked',
        ];

        $response = $this->postJson('/api/webhooks/midtrans', $payload);

        $response->assertOk()
                 ->assertJson(['message' => 'Already processed.']);

        
        $this->assertEquals('verified', $order->fresh()->status);
    }
}
