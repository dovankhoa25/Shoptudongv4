<?php

namespace Tests\Unit;

use App\Http\Resources\Api\ApiGemPriceResource;
use App\Http\Resources\Bot\BotResource;
use App\Http\Resources\GemOrder\GemOrderResource;
use App\Http\Resources\GemPrice\GemPriceResource;
use App\Models\Bot;
use App\Models\GemPrice;
use App\Models\GemTransaction;
use Tests\TestCase;

class AdminGemV3ResourceTest extends TestCase
{
    public function test_admin_and_public_quotes_preserve_old_rate_and_expose_whole_tickets_and_actual_charge(): void
    {
        $price = new GemPrice(['multiplier' => '12.35', 'gift_ticket_multiplier' => '12.35', 'status' => true]);
        $price->forceFill(['id' => 7, 'created_at' => now(), 'updated_at' => now()]);
        $expected = ['requested_amount' => 10000, 'charged_amount' => 9717, 'unused_amount' => 283, 'ticket_qty' => 12, 'gem_qty' => 120];

        foreach ([new GemPriceResource($price), new ApiGemPriceResource($price)] as $resource) {
            $data = $resource->resolve();
            $this->assertSame('12.35', $data['gift_ticket_multiplier']);
            $this->assertSame(123.5, $data['gift_ticket_gems_per_10k']);
            $this->assertSame($expected, $data['gift_ticket_quote_per_10k']);
            $this->assertSame(123, $data['consignment_quote_per_10k']['gem_qty']);
            $this->assertSame(10000, $data['consignment_quote_per_10k']['charged_amount']);
        }
        $this->assertSame('x12,35', (new GemPriceResource($price))->resolve()['multiplier_display']);
    }

    public function test_empty_ticket_override_uses_consignment_rate_with_integer_ticket_quote(): void
    {
        $price = new GemPrice(['multiplier' => '13.50', 'gift_ticket_multiplier' => null]);
        $price->forceFill(['id' => 8, 'created_at' => now(), 'updated_at' => now()]);
        $data = (new GemPriceResource($price))->resolve();

        $this->assertNull($data['gift_ticket_multiplier']);
        $this->assertSame(135.0, $data['gift_ticket_gems_per_10k']);
        $this->assertSame(['requested_amount' => 10000, 'charged_amount' => 9630, 'unused_amount' => 370, 'ticket_qty' => 13, 'gem_qty' => 130], $data['gift_ticket_quote_per_10k']);
    }

    public function test_admin_order_exposes_confirmed_receipts_without_private_processing_data(): void
    {
        $order = new GemTransaction([
            'delivery_method' => 'gift_ticket',
            'delivery_phase' => 'gifting', 'amount_vnd' => 9717, 'gem_qty' => 120,
            'price_at_transaction' => '12.35', 'bot_gem_spent_qty' => 20, 'delivered_gem_qty' => 20,
            'tickets_used_qty' => 2, 'status' => 'processing',
            'delivery_data' => ['claim'=>['character_name'=>'private-recipient','gem_qty'=>120,'delivery_method'=>'gift_ticket'],
                'private_intent' => ['secret' => 'should-not-be-rendered']],
        ]);
        $data = (new GemOrderResource($order))->resolve();

        $this->assertSame('Vé tặng ngọc', $data['delivery_method_label']);
        $this->assertSame('Đang tặng ngọc bằng vé', $data['delivery_phase_label']);
        $this->assertSame('x12,35', $data['multiplier_display']);
        $this->assertSame('9.717 VND', $data['amount_vnd_formatted']);
        $this->assertSame(20, $data['delivered_gem_qty']);
        $this->assertSame(2, $data['tickets_used_qty']);
        $this->assertTrue($data['has_delivery_checkpoint']);
        $this->assertArrayNotHasKey('processing_token', $data);
        $this->assertArrayNotHasKey('processing_owner', $data);
        $this->assertArrayNotHasKey('delivery_data', $data);
    }

    public function test_consignment_equipment_receipt_and_reconciliation_are_readable(): void
    {
        $order = new GemTransaction([
            'delivery_method' => 'consignment', 'delivery_phase' => 'review_required', 'status' => 'processing',
            'delivery_data' => ['delivery_item' => ['verified' => true]],
        ]);
        $data = (new GemOrderResource($order))->resolve();
        $this->assertTrue($data['equipment_delivered']);
        $this->assertSame('Đồ ký gửi', $data['delivery_method_label']);
        $this->assertSame('Đang đối chiếu tồn và giao dịch', $data['delivery_phase_label']);
    }

    public function test_gold_admin_resource_reports_password_presence_without_password_contents(): void
    {
        $bot = new Bot(['account_name' => 'test-bot', 'account_password' => 'test-password-private', 'type' => 'selling_sub', 'gold_qty' => 0, 'gold_bar_qty' => 999, 'status' => true]);
        $data = (new BotResource($bot))->resolve();

        $this->assertTrue($data['has_password']);
        $this->assertArrayNotHasKey('account_password', $data);
        $this->assertStringNotContainsString('test-password-private', json_encode($data, JSON_THROW_ON_ERROR));
        $this->assertSame(['selling_sub'], $data['types']);
        $this->assertSame(999, $data['gold_bar_qty']);
    }
}
