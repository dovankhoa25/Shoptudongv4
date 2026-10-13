<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Models\AtmTopup;
use App\Models\Card;
use App\Models\CardType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminDepositManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
        $this->travelTo(CarbonImmutable::parse('2026-10-11 00:30:00', 'Asia/Ho_Chi_Minh'));

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

    public function test_unmatched_bank_transfers_have_a_separate_filterable_tab_and_do_not_count_as_credited(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(AppPermission::DepositsView->value);
        $customer = User::factory()->create();
        $pending = $this->bankTopup($customer, [
            'user_id' => null,
            'provider' => 'gpmpay',
            'payment_code' => null,
            'status' => AtmTopup::STATUS_NEEDS_REVIEW,
            'review_reason' => 'Nội dung không có mã thanh toán hợp lệ.',
        ]);
        $completed = $this->bankTopup($customer);

        $this->actingAs($admin)->get(route('admin.deposits.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('bankTopups.data', 1)
            ->where('bankTopups.data.0.id', $completed->id)
            ->has('reviewTopups.data', 1)
            ->where('reviewTopups.data.0.id', $pending->id)
            ->where('reviewTopups.data.0.user', null)
            ->where('reviewTopups.data.0.status', AtmTopup::STATUS_NEEDS_REVIEW)
            ->where('reviewTopups.data.0.review_reason', $pending->review_reason)
            ->where('stats.bank_total', 100000)
            ->where('stats.pending_bank_topups', 1)
            ->where('can.resolve_bank_topups', false));

        $this->get(route('admin.deposits.index', ['review_provider' => 'dvkpay']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('reviewTopups.data', 1)
            ->where('reviewTopups.data.0.provider', 'dvkpay')
            ->where('filters.review.provider', 'dvkpay'));
        $this->get(route('admin.deposits.index', ['review_provider' => 'sepay']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('reviewTopups.data', 0));

        foreach (['transaction_id', 'sepay_id'] as $alias) {
            $this->get(route('admin.deposits.index', ['review_search' => $alias.':'.$pending->provider_transaction_id]))
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('reviewTopups.data', 1)->where('reviewTopups.data.0.id', $pending->id));
        }
    }

    public function test_bank_tab_defaults_to_the_entire_current_vietnamese_day(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(AppPermission::DepositsView->value);
        $customer = User::factory()->create();
        $this->bankTopup($customer, ['transaction_at' => '2026-10-10 23:59:59']);
        $first = $this->bankTopup($customer, ['transaction_at' => '2026-10-11 00:00:00']);
        $last = $this->bankTopup($customer, ['transaction_at' => '2026-10-11 23:59:59']);
        $this->bankTopup($customer, ['transaction_at' => '2026-10-12 00:00:00']);

        $this->actingAs($admin)->get(route('admin.deposits.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('bankToday', '2026-10-11')
            ->where('filters.bank.start_date', '2026-10-11')
            ->where('filters.bank.end_date', '2026-10-11')
            ->where('filters.bank.all_dates', 0)
            ->has('bankTopups.data', 2)
            ->where('bankTopups.data.0.id', $last->id)
            ->where('bankTopups.data.1.id', $first->id)
            ->where('stats.bank_total', 200000));
    }

    public function test_vietnamese_day_bounds_are_converted_to_the_database_timezone(): void
    {
        config(['app.timezone' => 'UTC']);
        $admin = User::factory()->create();
        $admin->givePermissionTo(AppPermission::DepositsView->value);
        $customer = User::factory()->create();
        $this->bankTopup($customer, ['transaction_at' => '2026-10-10 16:59:59']);
        $first = $this->bankTopup($customer, ['transaction_at' => '2026-10-10 17:00:00']);
        $last = $this->bankTopup($customer, ['transaction_at' => '2026-10-11 16:59:59']);
        $this->bankTopup($customer, ['transaction_at' => '2026-10-11 17:00:00']);

        $this->actingAs($admin)->get(route('admin.deposits.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('bankToday', '2026-10-11')
            ->has('bankTopups.data', 2)
            ->where('bankTopups.data.0.id', $last->id)
            ->where('bankTopups.data.1.id', $first->id)
            ->where('stats.bank_total', 200000));
    }

    public function test_bank_date_range_combines_with_source_filter_and_preserves_pagination(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(AppPermission::DepositsView->value);
        $customer = User::factory()->create();
        $this->bankTopup($customer, ['provider' => 'dvkpay', 'transaction_at' => '2026-10-08 23:59:59']);
        $first = $this->bankTopup($customer, ['provider' => 'gpmpay', 'transaction_at' => '2026-10-09 00:00:00']);
        $last = $this->bankTopup($customer, ['provider' => 'dvkpay', 'transaction_at' => '2026-10-10 23:59:59']);
        $this->bankTopup($customer, ['provider' => 'dvkpay', 'transaction_at' => '2026-10-11 00:00:00']);
        $this->bankTopup($customer, ['transaction_at' => '2026-10-09 12:00:00']);
        $params = [
            'bank_start_date' => '2026-10-09',
            'bank_end_date' => '2026-10-10',
            'bank_provider' => 'dvkpay',
            'bank_per_page' => 1,
        ];

        $this->actingAs($admin)->get(route('admin.deposits.index', $params))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.bank.start_date', '2026-10-09')
            ->where('filters.bank.end_date', '2026-10-10')
            ->where('bankTopups.meta.total', 2)
            ->has('bankTopups.data', 1)
            ->where('bankTopups.data.0.id', $last->id)
            ->where('stats.bank_total', 200000)
            ->where('bankTopups.links.next', fn ($url) => str_contains($url, 'bank_start_date=2026-10-09')
                && str_contains($url, 'bank_end_date=2026-10-10')));

        $this->get(route('admin.deposits.index', [...$params, 'bank_page' => 2]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('bankTopups.data.0.id', $first->id)
            ->where('stats.bank_total', 200000));
    }

    public function test_older_unmatched_transfers_remain_discoverable_outside_the_default_day(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(AppPermission::DepositsView->value);
        $customer = User::factory()->create();
        $pending = $this->bankTopup($customer, [
            'provider' => 'dvkpay',
            'transaction_at' => '2026-10-09 23:59:59',
            'user_id' => null,
            'payment_code' => null,
            'content' => '',
            'status' => AtmTopup::STATUS_NEEDS_REVIEW,
            'review_reason' => 'Nội dung không có mã shop<ID> hoặc vang<ID> hợp lệ.',
        ]);
        $completed = $this->bankTopup($customer);

        $this->actingAs($admin)->get(route('admin.deposits.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('bankTopups.data', 1)
            ->where('bankTopups.data.0.id', $completed->id)
            ->where('stats.pending_bank_topups', 1)
            ->where('stats.bank_total', 100000));

        $this->get(route('admin.deposits.index', ['bank_all_dates' => 1]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.bank.all_dates', 1)
            ->has('bankTopups.data', 1)
            ->where('bankTopups.data.0.id', $completed->id)
            ->has('reviewTopups.data', 1)
            ->where('reviewTopups.data.0.id', $pending->id)
            ->where('reviewTopups.data.0.content', '')
            ->where('reviewTopups.data.0.user', null)
            ->where('reviewTopups.data.0.review_reason', $pending->review_reason)
            ->where('stats.bank_total', 100000));
    }

    public function test_bank_date_filters_reject_invalid_and_reversed_ranges(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(AppPermission::DepositsView->value);
        $this->actingAs($admin);

        $this->getJson(route('admin.deposits.index', ['bank_start_date' => '11/10/2026']))
            ->assertUnprocessable()->assertJsonValidationErrors('bank_start_date');
        $this->getJson(route('admin.deposits.index', ['bank_end_date' => '2026-02-30']))
            ->assertUnprocessable()->assertJsonValidationErrors('bank_end_date');
        $this->getJson(route('admin.deposits.index', [
            'bank_start_date' => '2026-10-11', 'bank_end_date' => '2026-10-10',
        ]))->assertUnprocessable()->assertJsonValidationErrors('bank_end_date');
    }

    private function bankTopup(User $user, array $attributes = []): AtmTopup
    {
        return AtmTopup::query()->create([
            'user_id' => $user->id,
            'provider' => 'sepay',
            'provider_transaction_id' => (string) Str::uuid(),
            'gateway' => 'MBBank',
            'transaction_at' => now(),
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
            ...$attributes,
        ]);
    }
}
