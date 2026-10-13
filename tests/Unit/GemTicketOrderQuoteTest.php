<?php

namespace Tests\Unit;

use App\Models\GemPrice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GemTicketOrderQuoteTest extends TestCase
{
    #[DataProvider('ticketQuotes')]
    public function test_quote_rounds_whole_tickets_down_and_their_cost_up(int $requested, string $rate, int $tickets, int $charged): void
    {
        $price = new GemPrice(['multiplier' => 10, 'gift_ticket_multiplier' => $rate]);
        $quote = $price->quoteOrder($requested, 'gift_ticket');
        $this->assertSame($requested, $quote['requested_amount']);
        $this->assertSame($tickets, $quote['ticket_qty']);
        $this->assertSame($tickets * 10, $quote['gem_qty']);
        $this->assertSame($charged, $quote['charged_amount']);
        $this->assertSame($requested - $charged, $quote['unused_amount']);
        $this->assertSame($quote['gem_qty'], $price->calculateOrderGems($requested, 'gift_ticket'));
        $this->assertLessThanOrEqual($requested, $charged);
        $this->assertSame($tickets, $price->quoteOrder($charged, 'gift_ticket')['ticket_qty']);
        if ($charged > 0) {
            $this->assertSame($tickets - 1, $price->quoteOrder($charged - 1, 'gift_ticket')['ticket_qty']);
        }
    }

    public static function ticketQuotes(): array
    {
        return [
            'zero' => [0, '12.35', 0, 0],
            'less-than-one-ticket' => [809, '12.35', 0, 0],
            'first-ticket' => [810, '12.35', 1, 810],
            'fractional-rate' => [10000, '12.35', 12, 9717],
            'before-next-ticket' => [10526, '12.35', 12, 9717],
            'next-ticket' => [10527, '12.35', 13, 10527],
            'exact-ten-k' => [10000, '12.00', 12, 10000],
            'below-gem-rounding-boundary' => [10001, '9.99', 9, 9010],
            'just-before-ten-tickets' => [10010, '9.99', 9, 9010],
            'ten-tickets' => [10011, '9.99', 10, 10011],
            'min-rate' => [10001, '1.00', 1, 10000],
            'max-rate' => [10001, '100.00', 100, 10000],
            'large-request' => [1000000000, '12.35', 1235000, 1000000000],
        ];
    }

    public function test_fallback_rate_and_consignment_charge_stay_compatible(): void
    {
        $price = new GemPrice(['multiplier' => '12.35']);
        $this->assertSame(['requested_amount' => 10000, 'charged_amount' => 9717, 'unused_amount' => 283, 'ticket_qty' => 12, 'gem_qty' => 120], $price->quoteOrder(10000, 'gift_ticket'));
        $this->assertSame(['requested_amount' => 10000, 'charged_amount' => 10000, 'unused_amount' => 0, 'ticket_qty' => 0, 'gem_qty' => 123], $price->quoteOrder(10000));
    }
}
