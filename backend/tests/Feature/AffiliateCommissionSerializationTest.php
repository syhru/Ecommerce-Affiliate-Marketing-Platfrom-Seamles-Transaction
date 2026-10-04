<?php

namespace Tests\Feature;

use App\Models\AffiliateCommission;
use App\Models\AffiliateProfile;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AffiliateCommissionSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_commissions_expose_only_order_summary_with_own_scope_and_existing_pagination(): void
    {
        $affiliate = User::factory()->create(['role' => 'affiliate', 'email_verified_at' => now()]);
        $otherAffiliate = User::factory()->create(['role' => 'affiliate', 'email_verified_at' => now()]);
        foreach ([$affiliate, $otherAffiliate] as $user) {
            AffiliateProfile::factory()->forUser($user)->create(['status' => AffiliateProfile::STATUS_ACTIVE]);
        }
        $customer = User::factory()->create(['name' => 'Private Customer', 'email' => 'private@example.test']);
        $commissions = [];
        foreach (['pending', 'earned', 'cancelled', 'withdrawn'] as $index => $status) {
            $orderId = $this->createOrder($customer, $affiliate, 'REF-' . $index);
            $commissions[] = AffiliateCommission::create([
                'order_id' => $orderId,
                'affiliate_id' => $affiliate->id,
                'amount' => '12.50',
                'commission_rate' => '10.00',
                'status' => $status,
                'earned_at' => $status === 'earned' ? now() : null,
                'cancelled_at' => $status === 'cancelled' ? now() : null,
                'created_at' => now()->addSeconds($index),
            ]);
        }
        $foreignCommission = AffiliateCommission::create([
            'order_id' => $this->createOrder($customer, $otherAffiliate, 'FOREIGN'),
            'affiliate_id' => $otherAffiliate->id,
            'amount' => '99.00',
            'commission_rate' => '10.00',
            'status' => 'pending',
        ]);
        Sanctum::actingAs($affiliate);

        $returnedIds = [];
        foreach ([1, 2] as $page) {
            $response = $this->getJson('/api/affiliate/commissions?per_page=2&page=' . $page)
                ->assertOk()
                ->assertJsonPath('current_page', $page)
                ->assertJsonPath('last_page', 2)
                ->assertJsonPath('per_page', 2)
                ->assertJsonPath('total', 4)
                ->assertJsonCount(2, 'data');
            $payload = $response->json();
            $this->assertEqualsCanonicalizing([
                'current_page', 'data', 'first_page_url', 'from', 'last_page', 'last_page_url',
                'links', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total',
            ], array_keys($payload));
            $this->assertSame(($page - 1) * 2 + 1, $payload['from']);
            $this->assertSame($page * 2, $payload['to']);
            $this->assertSensitiveFieldsAbsent($payload);
            foreach ($payload['data'] as $item) {
                $returnedIds[] = $item['id'];
                $commission = collect($commissions)->firstWhere('id', $item['id']);
                $this->assertNotNull($commission);
                $this->assertEqualsCanonicalizing([
                    'id', 'order_id', 'affiliate_id', 'amount', 'commission_rate', 'status',
                    'earned_at', 'cancelled_at', 'created_at', 'updated_at', 'order',
                ], array_keys($item));
                $this->assertSame($commission->fresh()->attributesToArray(), array_diff_key($item, ['order' => true]));
                $this->assertSame([
                    'id' => $commission->order_id,
                    'order_number' => $commission->order->order_number,
                ], $item['order']);
            }
        }
        $this->assertEqualsCanonicalizing(array_map(fn ($commission) => $commission->id, $commissions), $returnedIds);
        $this->assertNotContains($foreignCommission->id, $returnedIds);
    }

    private function createOrder(User $customer, User $affiliate, string $number): int
    {
        return DB::table('orders')->insertGetId([
            'order_number' => $number,
            'customer_id' => $customer->id,
            'affiliate_id' => $affiliate->id,
            'subtotal' => 125,
            'commission_amount' => 12.5,
            'total_amount' => 125,
            'status' => Order::STATUS_PENDING,
            'shipping_address' => 'Private street 42',
            'notes' => 'Private internal note',
            'payment_method' => 'midtrans',
            'midtrans_snap_token' => 'private-snap-token',
            'midtrans_transaction_id' => 'private-transaction',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertSensitiveFieldsAbsent(array $payload): void
    {
        $forbidden = [
            'shipping_address', 'customer_id', 'customer', 'name', 'email', 'phone',
            'notes', 'midtrans_snap_token', 'midtrans_transaction_id', 'payment_method',
            'payment_verified_at', 'payment_metadata', 'internal_payment_metadata',
        ];
        foreach ($payload as $key => $value) {
            $this->assertNotContains($key, $forbidden, 'Sensitive response key: ' . $key);
            if (is_array($value)) {
                $this->assertSensitiveFieldsAbsent($value);
            }
        }
    }
}
