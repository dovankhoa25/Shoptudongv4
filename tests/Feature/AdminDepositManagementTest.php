<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Models\AtmTopup;
use App\Models\Card;
use App\Models\CardType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminDepositManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (AppPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_authorized_admin_can_view_card_and_bank_deposit_management_page(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(AppPermission::DepositsView->value);
        $customer = User::factory()->create();
        $cardType = CardType::query()->create([
            'telco' => 'viettel',
            'discount_rate' => 20,
            'status' => true,
        ]);
        $card = Card::query()->create([
            'user_id' => $customer->id,
            'card_type_id' => $cardType->id,
            'declared_value' => 100000,
            'value' => 100000,
            'amount_user' => 80000,
            'amount_api' => 80000,
            'difference' => 0,
            'discount_rate_at_time' => 20,
            'code' => '1234567890',
            'serial' => 'SERIAL123456',
            'trans_id' => 'CARD-PARTNER-1',
            'status' => Card::STATUS_COMPLETED,
            'loaded_type' => false,
            'partner_status' => 1,
            'partner_message' => 'SUCCESS',
            'partner_http_status' => 200,
            'partner_response_at' => now()->subSecond(),
            'callback_received_at' => now(),
        ]);
        $topup = $this->bankTopup($customer);

        $this->actingAs($admin)
            ->get(route('admin.deposits.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Deposits/Index')
                ->where('cardTypes.data.0.id', $cardType->id)
                ->where('cardTypes.data.0.cards_count', 1)
                ->where('cards.data.0.id', $card->id)
                ->where('cards.data.0.user.id', $customer->id)
                ->where('cards.data.0.code', '******7890')
                ->where('cards.data.0.serial', '********3456')
                ->where('cards.data.0.partner_status', 1)
                ->where('cards.data.0.partner_message', 'SUCCESS')
                ->where('cards.data.0.partner_http_status', 200)
                ->has('cards.data.0.partner_response_at')
                ->has('cards.data.0.callback_received_at')
                ->where('bankTopups.data.0.id', $topup->id)
                ->where('bankTopups.data.0.account_number', '******2845')
                ->where('stats.card_total', 80000)
                ->where('stats.bank_total', 100000)
                ->where('can.manage_card_types', false));
    }

    public function test_card_type_manager_can_create_update_and_validate_card_types(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo([
            AppPermission::DepositsView->value,
            AppPermission::DepositsManageCardTypes->value,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.card-types.store'), [
                'telco' => ' VIETTEL ',
                'discount_rate' => 17.5,
                'status' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.telco', 'viettel')
            ->assertJsonPath('data.discount_rate', 17.5)
            ->assertJsonPath('data.status', true);

        $cardType = CardType::query()->sole();

        $this->actingAs($admin)
            ->putJson(route('admin.deposits.card-types.update', $cardType), [
                'telco' => 'viettel',
                'discount_rate' => 25,
                'status' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.discount_rate', 25)
            ->assertJsonPath('data.status', false);

        $this->assertDatabaseHas('card_types', [
            'id' => $cardType->id,
            'telco' => 'viettel',
            'discount_rate' => 25,
            'status' => false,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.card-types.store'), [
                'telco' => 'viettel',
                'discount_rate' => 100,
                'status' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['telco', 'discount_rate']);
    }

    public function test_deposit_routes_enforce_view_and_manage_permissions(): void
    {
        $unauthorized = User::factory()->create();
        $this->actingAs($unauthorized)
            ->get(route('admin.deposits.index'))
            ->assertForbidden();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(AppPermission::DepositsView->value);

        $this->actingAs($viewer)
            ->postJson(route('admin.deposits.card-types.store'), [
                'telco' => 'mobifone',
                'discount_rate' => 20,
                'status' => true,
            ])
            ->assertForbidden();
    }

    private function bankTopup(User $user): AtmTopup
    {
        return AtmTopup::query()->create([
            'user_id' => $user->id,
            'provider' => 'sepay',
            'provider_transaction_id' => '75172732',
            'gateway' => 'MBBank',
            'transaction_at' => '2026-08-20 21:58:27',
            'account_number' => '0357602845',
            'sub_account' => 'VQRQAKUOX9442',
            'payment_code' => 'shop'.$user->id,
            'content' => 'Chuyen khoan shop'.$user->id,
            'transfer_type' => 'in',
            'amount' => 100000,
            'reference_code' => 'FT26233120302804',
            'accumulated' => 0,
            'description' => 'BankAPINotify',
            'status' => AtmTopup::STATUS_COMPLETED,
            'payload' => ['id' => 75172732],
        ]);
    }
}
