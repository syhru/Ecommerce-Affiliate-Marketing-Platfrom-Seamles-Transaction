<?php

namespace Tests\Feature;

use App\Filament\Resources\CommissionResource;
use App\Filament\Resources\CommissionResource\Pages\ListCommissions;
use App\Models\AffiliateCommission;
use App\Models\AffiliateProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Filament\Forms\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WS-03 / R3-F-02 — the generic Filament commission form must not be able to
 * write commission lifecycle state.
 *
 * `pending → earned` credits balance/total_earned and `pending → cancelled`
 * voids a commission; both are owned exclusively by AffiliateService. A bare
 * form edit would skip those operations, so `status`, `earned_at` and
 * `cancelled_at` are `disabled()`. In Filament a disabled field is also not
 * dehydrated, and ComponentContainer::getState() drops non-dehydrated paths
 * from the data before it reaches the model — so the field cannot be persisted
 * through the generic form at all.
 *
 * `CommissionResource` exposes no edit page or edit action (verified against
 * the Git baseline: only `ViewAction`, and pages `index`/`view`). This test
 * exercises the declared form schema through the real Livewire page rather
 * than opening an edit capability purely to test it.
 */
class CommissionResourceFormTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperadmin(): User
    {
        return User::factory()->create([
            'role'              => 'superadmin',
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
    }

    private function makeCommission(): AffiliateCommission
    {
        $customer         = User::factory()->create();
        $affiliateUser    = User::factory()->affiliate()->create();
        $affiliateProfile = AffiliateProfile::factory()->forUser($affiliateUser)->create([
            'status'          => 'active',
            'commission_rate' => 10,
        ]);

        $product = Product::create([
            'name'        => 'Test Pump',
            'brand'       => 'TDR',
            'type'        => 'pump',
            'category'    => 'motor',
            'description' => 'A test product',
            'price'       => 75000,
            'stock'       => 8,
            'is_active'   => true,
        ]);

        $order = Order::create([
            'order_number'      => 'TDR-R3F02-' . uniqid(),
            'customer_id'       => $customer->id,
            'affiliate_id'      => $affiliateUser->id,
            'subtotal'          => 150000,
            'commission_amount' => 15000,
            'total_amount'      => 150000,
            'status'            => Order::STATUS_VERIFIED,
            'shipping_address'  => 'Jl. Test No. 1, Jakarta',
        ]);

        OrderItem::create([
            'order_id'       => $order->id,
            'product_id'     => $product->id,
            'product_name'   => $product->name,
            'product_price'  => $product->price,
            'quantity'       => 2,
            'subtotal'       => 150000,
            'affiliate_code' => $affiliateProfile->referral_code,
        ]);

        return AffiliateCommission::create([
            'order_id'        => $order->id,
            'affiliate_id'    => $affiliateUser->id,
            'amount'          => 15000,
            'commission_rate' => 10,
            'status'          => AffiliateCommission::STATUS_PENDING,
        ]);
    }

    public function test_resource_exposes_no_edit_capability(): void
    {
        // The Git baseline has no edit page and no edit action. Guarding it
        // keeps the lifecycle protection from being re-introduced as a new
        // admin capability (scope-integrity check).
        $this->assertSame(
            ['index', 'view'],
            array_keys(CommissionResource::getPages()),
            'CommissionResource must not expose an edit page.'
        );

        $this->actingAs($this->makeSuperadmin());

        // The only table row action is `view` — no edit action exists.
        $page = Livewire::test(ListCommissions::class)->instance();

        $actionNames = array_map(
            static fn ($action): string => $action->getName(),
            $page->getTable()->getActions()
        );

        $this->assertSame(
            ['view'],
            array_values($actionNames),
            'CommissionResource must not expose an edit action.'
        );
    }

    public function test_lifecycle_fields_are_disabled_and_not_dehydrated(): void
    {
        $this->actingAs($this->makeSuperadmin());

        $page = Livewire::test(ListCommissions::class)->instance();
        $form = CommissionResource::form(Form::make($page));
        $form->fill([]);

        foreach ($form->getFlatFields(withHidden: true) as $field) {
            if (! in_array($field->getName(), ['status', 'earned_at', 'cancelled_at'], true)) {
                continue;
            }

            $this->assertTrue(
                $field->isDisabled(),
                "Field [{$field->getName()}] must be disabled so the generic form cannot write commission lifecycle state."
            );

            $this->assertFalse(
                $field->isDehydrated(),
                "Field [{$field->getName()}] must not be dehydrated, so it is excluded from the submitted data."
            );
        }
    }

    public function test_lifecycle_state_cannot_be_persisted_through_the_generic_form(): void
    {
        $this->actingAs($this->makeSuperadmin());

        $commission = $this->makeCommission();
        $this->assertSame(AffiliateCommission::STATUS_PENDING, $commission->fresh()->status);

        $page = Livewire::test(ListCommissions::class)->instance();

        $form = CommissionResource::form(Form::make($page))
            ->statePath('mountedTableActionsData.0')
            ->model($commission)
            ->fill([
                'order_id'        => $commission->order_id,
                'affiliate_id'    => $commission->affiliate_id,
                'amount'          => 15000,
                'commission_rate' => 10,
                'status'          => AffiliateCommission::STATUS_EARNED,
                'earned_at'       => now()->toDateTimeString(),
                'cancelled_at'    => now()->toDateTimeString(),
            ]);

        // This is exactly what Filament does when a form is submitted: the
        // dehydrateState() pass drops non-dehydrated (disabled) fields from the
        // payload before it reaches the model.
        $data = $form->getState(shouldCallHooksBefore: false);

        $this->assertArrayNotHasKey('status', $data, 'status must be excluded from the submitted form data');
        $this->assertArrayNotHasKey('earned_at', $data, 'earned_at must be excluded from the submitted form data');
        $this->assertArrayNotHasKey('cancelled_at', $data, 'cancelled_at must be excluded from the submitted form data');

        $commission->update($data);

        $fresh = $commission->fresh();

        $this->assertSame(AffiliateCommission::STATUS_PENDING, $fresh->status, 'status must not be writable through the generic form');
        $this->assertNull($fresh->earned_at, 'earned_at must not be writable through the generic form');
        $this->assertNull($fresh->cancelled_at, 'cancelled_at must not be writable through the generic form');

        // A bare `pending → earned` edit would have credited nothing, so the
        // affiliate balance stays at zero — the credit belongs to
        // AffiliateService::earnCommission(), which was never called.
        $this->assertEquals(0, (float) AffiliateProfile::where('user_id', $fresh->affiliate_id)->value('balance'));
    }
}
