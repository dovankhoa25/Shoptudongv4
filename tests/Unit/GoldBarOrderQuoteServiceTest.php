<?php

namespace Tests\Unit;

use App\Services\GoldBarOrderQuoteService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GoldBarOrderQuoteServiceTest extends TestCase
{
    #[DataProvider('quotes')]
    public function test_it_quotes_only_deliverable_gold_bars(
        int $requestedAmount,
        int $rate,
        int $expectedBars,
        int $expectedCharge,
        int $expectedUnused,
        int $expectedNextBarAmount,
    ): void {
        $quote = (new GoldBarOrderQuoteService)->quote($requestedAmount, $rate);

        $this->assertSame($expectedBars, $quote['gold_bar_qty']);
        $this->assertSame($expectedBars * GoldBarOrderQuoteService::GOLD_PER_BAR, $quote['actual_gold']);
        $this->assertSame($expectedCharge, $quote['charged_amount']);
        $this->assertSame($expectedUnused, $quote['unused_amount']);
        $this->assertSame($expectedNextBarAmount, $quote['next_bar_amount']);
    }

    public static function quotes(): array
    {
        return [
            'below one bar' => [5000, 85, 0, 0, 5000, 435295],
            'one bar with unused budget' => [500000, 85, 1, 435295, 64705, 870589],
            'canonical one bar price' => [435295, 85, 1, 435295, 0, 870589],
            'two exact bars at divisible rate' => [20000, 3700, 2, 20000, 0, 30000],
        ];
    }
}
