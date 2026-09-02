<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\CardType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CardHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_only_their_paginated_card_history(): void
    {
        $cardType = CardType::query()->create([
            'telco' => 'mobifone',
            'discount_rate' => 20,
            'status' => true,
        ]);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $olderCard = $this->createCard($user, $cardType, [
            'code' => 'USER-CODE-001',
            'serial' => 'USER-SERIAL-001',
            'status' => Card::STATUS_COMPLETED,
            'amount_user' => 8000,
        ]);
        $newerCard = $this->createCard($user, $cardType, [
            'code' => 'USER-CODE-002',
            'serial' => 'USER-SERIAL-002',
            'status' => Card::STATUS_PENDING,
            'partner_status' => 99,
            'partner_message' => 'PENDING',
            'partner_http_status' => 200,
            'partner_response_at' => now(),
            'callback_received_at' => now(),
        ]);
        $this->createCard($otherUser, $cardType, [
            'code' => 'OTHER-CODE-001',
            'serial' => 'OTHER-SERIAL-001',
        ]);

        Passport::actingAs($user, ['profile:read']);

        $this->getJson('/api/profile/history-card?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newerCard->id)
            ->assertJsonPath('data.0.telco', 'mobifone')
            ->assertJsonPath('data.0.declared_value.value', 10000)
            ->assertJsonPath('data.0.declared_value.formatted', '10,000 VND')
            ->assertJsonPath('data.0.discount_rate_at_time.value', 20)
            ->assertJsonPath('data.0.discount_rate_at_time.formatted', '20.00%')
            ->assertJsonPath('data.0.status.value', Card::STATUS_PENDING)
            ->assertJsonPath('data.0.status.label', 'Đang chờ xử lý')
            ->assertJsonPath('data.0.code', 'USER-CODE-002')
            ->assertJsonMissingPath('data.0.partner_status')
            ->assertJsonMissingPath('data.0.partner_message')
            ->assertJsonMissingPath('data.0.partner_http_status')
            ->assertJsonMissingPath('data.0.partner_response_at')
            ->assertJsonMissingPath('data.0.callback_received_at')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissing(['code' => 'OTHER-CODE-001']);

        $this->getJson('/api/profile/history-card?status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $olderCard->id)
            ->assertJsonPath('data.0.amount_user.value', 8000);

        $this->getJson('/api/profile/history-card?search=SERIAL-001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $olderCard->id)
            ->assertJsonMissing(['code' => 'OTHER-CODE-001']);
    }

    public function test_card_history_requires_profile_scope_and_valid_filters(): void
    {
        $this->getJson('/api/profile/history-card')->assertUnauthorized();

        Passport::actingAs(User::factory()->create(), ['balance:deposit']);
        $this->getJson('/api/profile/history-card')->assertForbidden();

        Passport::actingAs(User::factory()->create(), ['profile:read']);
        $this->getJson('/api/profile/history-card?status=unknown&per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'per_page']);
    }

    /** @param array<string, mixed> $attributes */
    private function createCard(User $user, CardType $cardType, array $attributes = []): Card
    {
        return Card::query()->create(array_merge([
            'user_id' => $user->id,
            'card_type_id' => $cardType->id,
            'declared_value' => 10000,
            'amount_user' => 0,
            'discount_rate_at_time' => 20,
            'code' => 'DEFAULT-CODE-'.$user->id,
            'serial' => 'DEFAULT-SERIAL-'.$user->id,
            'status' => Card::STATUS_PENDING,
            'loaded_type' => false,
        ], $attributes));
    }
}
