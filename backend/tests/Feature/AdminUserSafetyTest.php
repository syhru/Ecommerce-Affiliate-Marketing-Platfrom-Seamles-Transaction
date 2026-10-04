<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->superadmin()->create(['email_verified_at' => now()]);
        $this->actingAs($admin);
        return $admin;
    }

    public function test_generic_create_and_edit_cannot_provision_superadmin(): void
    {
        $this->admin();
        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Forbidden', 'email' => 'forbidden@example.test',
            'password' => 'LongTestPassword123!', 'role' => 'superadmin', 'is_active' => true,
        ])->call('create')->assertHasFormErrors(['role']);
        $this->assertDatabaseMissing('users', ['email' => 'forbidden@example.test']);
        $target = User::factory()->create();
        Livewire::test(EditUser::class, ['record' => $target->id])
            ->set('data.role', 'superadmin')->call('save')->assertHasFormErrors(['role']);
        $this->assertSame('customer', $target->fresh()->role);
    }

    public function test_last_eligible_admin_cannot_be_demoted_deactivated_or_unverified(): void
    {
        $admin = $this->admin();
        foreach (['role' => 'customer', 'is_active' => false, 'email' => 'changed@example.test'] as $field => $value) {
            Livewire::test(EditUser::class, ['record' => $admin->id])
                ->set('data.'.$field, $value)->call('save')->assertHasFormErrors([$field]);
            $fresh = $admin->fresh();
            $this->assertSame('superadmin', $fresh->role);
            $this->assertTrue($fresh->is_active);
            $this->assertNotNull($fresh->email_verified_at);
            $this->assertSame($admin->email, $fresh->email);
        }
    }

    public function test_legitimate_role_transitions_and_existing_admin_ordinary_edit_work(): void
    {
        $admin = $this->admin();
        Livewire::test(EditUser::class, ['record' => $admin->id])
            ->set('data.name', 'Renamed admin')->call('save')->assertHasNoFormErrors();
        $this->assertSame('superadmin', $admin->fresh()->role);
        $this->assertSame('Renamed admin', $admin->fresh()->name);
        $target = User::factory()->create();
        foreach (['affiliate', 'customer'] as $role) {
            Livewire::test(EditUser::class, ['record' => $target->id])
                ->set('data.role', $role)->call('save')->assertHasNoFormErrors();
            $this->assertSame($role, $target->fresh()->role);
        }
        User::factory()->superadmin()->create(['email_verified_at' => now()]);
        Livewire::test(EditUser::class, ['record' => $admin->id])
            ->set('data.is_active', false)->call('save')->assertHasNoFormErrors();
        $this->assertFalse($admin->fresh()->is_active);
    }

    public function test_ineligible_admin_does_not_count_as_recovery_and_stale_record_cannot_remove_last(): void
    {
        $admin = $this->admin();
        User::factory()->superadmin()->create(['email_verified_at' => now(), 'is_active' => false]);
        User::factory()->superadmin()->create();
        Livewire::test(EditUser::class, ['record' => $admin->id])
            ->set('data.is_active', false)->call('save')->assertHasFormErrors(['is_active']);
        $other = User::factory()->superadmin()->create(['email_verified_at' => now()]);
        $page = Livewire::test(EditUser::class, ['record' => $admin->id]);
        app(\App\Services\AdminUserService::class)->update($other, ['is_active' => false]);
        $page->set('data.role', 'affiliate')->call('save')->assertHasFormErrors(['role']);
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertSame('superadmin', $admin->fresh()->role);
    }

    public function test_user_history_survives_forced_generic_deletion(): void
    {
        $admin = $this->admin();
        $profile = \App\Models\AffiliateProfile::factory()->create(['balance' => 200000]);
        $customer = User::factory()->create();
        $order = \App\Models\Order::create([
            'order_number' => 'USER-HISTORY', 'customer_id' => $customer->id,
            'affiliate_id' => $profile->user_id, 'subtotal' => 100000,
            'total_amount' => 100000, 'status' => 'verified', 'shipping_address' => 'Test address',
        ]);
        $commission = \App\Models\AffiliateCommission::create([
            'order_id' => $order->id, 'affiliate_id' => $profile->user_id,
            'amount' => 10000, 'commission_rate' => 10, 'status' => 'pending',
        ]);
        $withdrawal = app(\App\Services\AffiliateService::class)->processWithdrawal($profile, 100000, [
            'bank_name' => 'Bank', 'bank_account_number' => '123', 'bank_account_holder' => 'Holder',
        ]);
        Livewire::test(ListUsers::class)->set('selectedTableRecords', [$admin->id, $customer->id, $profile->user_id])
            ->call('mountTableBulkAction', 'delete')->call('callMountedTableBulkAction');
        foreach ([$admin, $customer, $profile, $order, $commission, $withdrawal] as $record) {
            $this->assertNotNull($record->fresh());
        }
        $this->assertEquals(100000, $profile->fresh()->balance);
    }

    public function test_generic_user_deletion_is_removed_including_self_last_and_mixed_bulk(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $this->assertFalse(UserResource::canDelete($admin));
        $this->assertFalse(UserResource::canDelete($customer));
        $this->assertFalse(UserResource::canDeleteAny());
        Livewire::test(ListUsers::class)->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete')
            ->set('selectedTableRecords', [$admin->id, $customer->id])
            ->call('mountTableBulkAction', 'delete')->call('callMountedTableBulkAction')
            ->call('mountTableAction', 'delete', $admin->id)->call('callMountedTableAction');
        $this->assertNotNull($admin->fresh());
        $this->assertNotNull($customer->fresh());
    }
}
