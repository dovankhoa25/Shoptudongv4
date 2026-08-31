<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminServiceOrderOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate(AppPermission::ServiceOrdersView->value, 'web');
        Permission::findOrCreate(AppPermission::ServiceOrdersProcess->value, 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('super-admin', 'web');
        Role::findOrCreate('ctv', 'web');
    }

    public function test_ctv_only_sees_service_orders_assigned_to_them(): void
    {
        $viewer = $this->receiver('ctv');
        $otherReceiver = $this->receiver('ctv');
        $ownOrder = $this->orderFor($viewer);
        $this->orderFor($otherReceiver);

        $viewer->givePermissionTo(AppPermission::ServiceOrdersView->value);

        $this->actingAs($viewer)
            ->get(route('admin.services.orders.receiver'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ServiceOrders/Receiver')
                ->has('service_orders.data', 1)
                ->where('service_orders.data.0.id', $ownOrder->id)
                ->where('service_orders.data.0.receiver.id', $viewer->id));
    }

    public function test_admin_and_super_admin_see_every_assigned_service_order(): void
    {
        $admin = $this->receiver('admin');
        $superAdmin = $this->receiver('super-admin');
        $firstReceiver = $this->receiver('ctv');
        $secondReceiver = $this->receiver('ctv');
        $this->orderFor($firstReceiver);
        $this->orderFor($secondReceiver);

        $admin->givePermissionTo(AppPermission::ServiceOrdersView->value);

        foreach ([$admin, $superAdmin] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('admin.services.orders.receiver'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Admin/ServiceOrders/Receiver')
                    ->has('service_orders.data', 2));
        }
    }

    public function test_ctv_cannot_complete_another_receivers_order_by_id(): void
    {
        $viewer = $this->receiver('ctv');
        $otherReceiver = $this->receiver('ctv');
        $otherOrder = $this->orderFor($otherReceiver);

        $viewer->givePermissionTo(AppPermission::ServiceOrdersProcess->value);

        $this->actingAs($viewer)
            ->put(route('admin.services.orders.receiver.complete', $otherOrder->id))
            ->assertNotFound();

        $this->assertSame('approved', $otherOrder->refresh()->status);
        $this->assertSame(0, (int) $otherReceiver->refresh()->balance);
    }

    private function receiver(string $role): User
    {
        $user = User::factory()->create(['balance' => 0]);
        $user->assignRole($role);

        return $user;
    }

    private function orderFor(User $receiver): ServiceOrder
    {
        $service = Service::query()->create([
            'name' => 'Dịch vụ kiểm thử '.$receiver->id,
            'default_price' => 5000,
            'status' => true,
        ]);
        $customer = User::factory()->create();

        return ServiceOrder::withoutReceiverOwnedScope()->create([
            'service_id' => $service->id,
            'user_id' => $customer->id,
            'receiver_id' => $receiver->id,
            'service_price' => 5000,
            'account' => 'account-'.$receiver->id,
            'password' => 'secret',
            'description' => 'Đơn kiểm thử phân quyền người nhận.',
            'status' => 'approved',
        ]);
    }
}
