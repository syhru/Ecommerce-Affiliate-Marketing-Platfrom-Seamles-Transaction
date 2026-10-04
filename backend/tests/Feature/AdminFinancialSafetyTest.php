<?php

namespace Tests\Feature;

use App\Filament\Resources\AffiliateResource\Pages\EditAffiliate;
use App\Models\AffiliateProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminFinancialSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create([
            'role' => 'superadmin', 'is_active' => true, 'email_verified_at' => now(),
        ]));
    }

    public function test_affiliate_generic_save_preserves_money_and_ownership(): void
    {
        $profile = AffiliateProfile::factory()->create(['balance' => 100000, 'total_earned' => 150000]);
        $other = User::factory()->create();

        Livewire::test(EditAffiliate::class, ['record' => $profile->getRouteKey()])
            ->set('data.balance', 999999)
            ->set('data.total_earned', 999999)
            ->set('data.user_id', $other->id)
            ->set('data.status', 'active')
            ->set('data.approved_by', $other->id)
            ->set('data.commission_rate', '12.5')
            ->set('data.bank_name', 'Updated bank')
            ->call('save')->assertHasNoFormErrors();

        $fresh = $profile->fresh();
        $this->assertEquals(100000, $fresh->balance);
        $this->assertEquals(150000, $fresh->total_earned);
        $this->assertSame($profile->user_id, $fresh->user_id);
        $this->assertSame($profile->status, $fresh->status);
        $this->assertSame($profile->approved_by, $fresh->approved_by);
        $this->assertEquals(12.5, $fresh->commission_rate);
        $this->assertSame('Updated bank', $fresh->bank_name);
    }

    public function test_withdrawal_save_preserves_reservation_and_reject_refunds_original_amount(): void
    {
        $profile = AffiliateProfile::factory()->create(['balance' => 100000]);
        $other = User::factory()->create();
        $bank = ['bank_name' => 'Original bank', 'bank_account_number' => '123', 'bank_account_holder' => 'Original holder'];
        $service = app(\App\Services\AffiliateService::class);
        $withdrawal = $service->processWithdrawal($profile, 100000, $bank);

        Livewire::test(\App\Filament\Resources\WithdrawalResource\Pages\EditWithdrawal::class, ['record' => $withdrawal->getRouteKey()])
            ->set('data.affiliate_id', $other->id)
            ->set('data.amount', 200000)
            ->set('data.bank_name', 'Forged bank')
            ->set('data.bank_account_number', '999')
            ->set('data.bank_account_holder', 'Forged holder')
            ->set('data.status', 'completed')
            ->set('data.processed_by', $other->id)
            ->set('data.processed_at', now()->toDateTimeString())
            ->set('data.rejection_reason', 'Forged reason')
            ->set('data.notes', 'Legitimate note')
            ->call('save')->assertHasNoFormErrors();

        $fresh = $withdrawal->fresh();
        $this->assertSame($profile->user_id, $fresh->affiliate_id);
        $this->assertEquals(100000, $fresh->amount);
        foreach ($bank as $field => $value) {
            $this->assertSame($value, $fresh->$field);
        }
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->processed_at);
        $this->assertNull($fresh->processed_by);
        $this->assertNull($fresh->rejection_reason);
        $this->assertSame('Legitimate note', $fresh->notes);
        $this->assertEquals(0, $profile->fresh()->balance);

        Livewire::test(\App\Filament\Resources\WithdrawalResource\Pages\ListWithdrawals::class)
            ->callTableAction('reject', $fresh, ['reason' => 'Rejected by admin'])
            ->assertHasNoTableActionErrors();
        $service->rejectWithdrawal($withdrawal, 'Repeated reject');
        $service->completeWithdrawal($withdrawal);
        $this->assertEquals(100000, $profile->fresh()->balance);
        $this->assertSame('rejected', $withdrawal->fresh()->status);
    }

    private function history(): array
    {
        $profile = AffiliateProfile::factory()->create(['balance' => 200000]);
        $product = \App\Models\Product::create([
            'name' => 'History product', 'slug' => 'history-product', 'brand' => 'TDR',
            'type' => 'pump', 'category' => 'motor', 'price' => 100000, 'stock' => 8,
        ]);
        $order = \App\Models\Order::create([
            'order_number' => 'WS06-HISTORY', 'customer_id' => User::factory()->create()->id,
            'affiliate_id' => $profile->user_id, 'subtotal' => 100000,
            'total_amount' => 100000, 'commission_amount' => 10000, 'status' => 'verified',
            'shipping_address' => 'Test address',
        ]);
        $item = \App\Models\OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'product_price' => 100000, 'quantity' => 1, 'subtotal' => 100000,
        ]);
        $commission = \App\Models\AffiliateCommission::create([
            'order_id' => $order->id, 'affiliate_id' => $profile->user_id,
            'amount' => 10000, 'commission_rate' => 10, 'status' => 'pending',
        ]);
        $withdrawal = app(\App\Services\AffiliateService::class)->processWithdrawal($profile, 100000, [
            'bank_name' => 'Bank', 'bank_account_number' => '123', 'bank_account_holder' => 'Holder',
        ]);
        return compact('profile', 'product', 'order', 'item', 'commission', 'withdrawal');
    }

    public function test_financial_resources_deny_generic_delete_and_preserve_linked_history(): void
    {
        $history = $this->history();
        $cases = [
            [\App\Filament\Resources\AffiliateResource::class, \App\Filament\Resources\AffiliateResource\Pages\ListAffiliates::class, $history['profile']],
            [\App\Filament\Resources\WithdrawalResource::class, \App\Filament\Resources\WithdrawalResource\Pages\ListWithdrawals::class, $history['withdrawal']],
            [\App\Filament\Resources\OrderResource::class, \App\Filament\Resources\OrderResource\Pages\ListOrders::class, $history['order']],
        ];
        foreach ($cases as [$resource, $page, $record]) {
            $this->assertFalse($resource::canDelete($record));
            $this->assertFalse($resource::canDeleteAny());
            Livewire::test($page)
                ->assertTableActionDoesNotExist('delete')
                ->assertTableBulkActionDoesNotExist('delete')
                ->set('selectedTableRecords', [$record->getKey()])
                ->call('mountTableBulkAction', 'delete')
                ->call('callMountedTableBulkAction')
                ->call('mountTableAction', 'delete', $record->getKey())
                ->call('callMountedTableAction');
        }
        foreach ([
            [EditAffiliate::class, $history['profile']],
            [\App\Filament\Resources\WithdrawalResource\Pages\EditWithdrawal::class, $history['withdrawal']],
        ] as [$page, $record]) {
            Livewire::test($page, ['record' => $record->getRouteKey()])
                ->assertActionDoesNotExist('delete')
                ->call('mountAction', 'delete')->call('callMountedAction');
        }
        foreach ($history as $record) {
            $this->assertNotNull($record->fresh());
        }
        $this->assertEquals(100000, $history['profile']->fresh()->balance);
        $this->assertSame('pending', $history['withdrawal']->fresh()->status);
    }

    public function test_product_force_delete_is_denied_while_soft_delete_and_restore_preserve_history(): void
    {
        $history = $this->history();
        $product = $history['product'];
        $resource = \App\Filament\Resources\ProductResource::class;
        $page = \App\Filament\Resources\ProductResource\Pages\ListProducts::class;
        $this->assertFalse($resource::canForceDelete($product));
        $this->assertFalse($resource::canForceDeleteAny());
        Livewire::test($page)->callTableAction('delete', $product);
        $this->assertSoftDeleted($product);
        Livewire::test($page)
            ->filterTable('trashed', true)
            ->assertTableBulkActionDoesNotExist('forceDelete')
            ->set('selectedTableRecords', [$product->id])
            ->call('mountTableBulkAction', 'forceDelete')
            ->call('callMountedTableBulkAction');
        $this->assertSoftDeleted($product);
        $this->assertNotNull($history['item']->fresh());
        Livewire::test($page)->filterTable('trashed', true)->callTableAction('restore', $product->fresh());
        $this->assertNotSoftDeleted($product);
        foreach (['order', 'item', 'commission', 'withdrawal'] as $key) {
            $this->assertNotNull($history[$key]->fresh());
        }
    }

    public function test_affiliate_commission_rate_rejects_non_numeric_state(): void
    {
        $profile = AffiliateProfile::factory()->create();
        Livewire::test(EditAffiliate::class, ['record' => $profile->getRouteKey()])
            ->set('data.commission_rate', 'not-a-number')
            ->call('save')->assertHasFormErrors(['commission_rate' => 'numeric']);
        $this->assertEquals($profile->commission_rate, $profile->fresh()->commission_rate);
    }
}
