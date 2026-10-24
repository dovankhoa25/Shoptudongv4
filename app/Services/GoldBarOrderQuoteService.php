<?php

namespace App\Services;

use InvalidArgumentException;

class GoldBarOrderQuoteService
{
    public const GOLD_PER_BAR = 37_000_000;

    /**
     * @return array{
     *     requested_amount: int,
     *     price_per_vnd: int,
     *     theoretical_gold: int,
     *     gold_bar_qty: int,
     *     actual_gold: int,
     *     charged_amount: int,
     *     unused_amount: int,
     *     minimum_amount: int,
     *     next_bar_amount: int
     * }
     */
    public function quote(int $requestedAmount, int|string $pricePerVnd, int $goldBarTemplateId=GoldBarAsset::STANDARD): array
    {
        $unit=GoldBarAsset::value($goldBarTemplateId);
        $price = $this->normalizePositiveInteger($pricePerVnd, 'Tỷ giá vàng phải lớn hơn 0.');
        $requested = max($requestedAmount, 0);
        $theoreticalGold = bcmul((string) $requested, $price);
        $goldBarQty = (int) bcdiv($theoreticalGold, (string) $unit, 0);
        $actualGold = bcmul((string) $goldBarQty, (string) $unit);
        $chargedAmount = $goldBarQty > 0
            ? $this->ceilDivide($actualGold, $price)
            : '0';
        $minimumAmount = $this->ceilDivide((string) $unit, $price);
        $nextBarGold = bcmul((string) ($goldBarQty + 1), (string) $unit);
        $nextBarAmount = $this->ceilDivide($nextBarGold, $price);
        $unusedAmount = bcsub((string) $requested, $chargedAmount);

        return [
            'gold_bar_template_id'=>$goldBarTemplateId,'gold_per_bar'=>$unit,
            'requested_amount' => $requested,
            'price_per_vnd' => $this->toInteger($price),
            'theoretical_gold' => $this->toInteger($theoreticalGold),
            'gold_bar_qty' => $goldBarQty,
            'actual_gold' => $this->toInteger($actualGold),
            'charged_amount' => $this->toInteger($chargedAmount),
            'unused_amount' => $this->toInteger($unusedAmount),
            'minimum_amount' => $this->toInteger($minimumAmount),
            'next_bar_amount' => $this->toInteger($nextBarAmount),
        ];
    }

    private function ceilDivide(string $dividend, string $divisor): string
    {
        if (bccomp($dividend, '0') === 0) {
            return '0';
        }

        return bcdiv(bcadd($dividend, bcsub($divisor, '1')), $divisor, 0);
    }

    /** Quote direct gold without converting a remainder into bars. */
    public function quotePure(int $requestedAmount, int|string $pricePerVnd): array
    {
        $price = $this->normalizePositiveInteger($pricePerVnd, 'Tỷ giá vàng phải lớn hơn 0.');
        $requested = max($requestedAmount, 0);
        $gold = $this->toInteger(bcmul((string) $requested, $price));

        if ($gold > 2_147_483_647) {
            throw new InvalidArgumentException('Vàng tươi tối đa 2.147.483.647 vàng mỗi đơn. Vui lòng giảm số tiền.');
        }

        return [
            'gold_bar_template_id' => GoldBarAsset::STANDARD,
            'gold_per_bar' => GoldBarAsset::value(GoldBarAsset::STANDARD),
            'requested_amount' => $requested,
            'price_per_vnd' => $this->toInteger($price),
            'theoretical_gold' => $gold,
            'gold_bar_qty' => 0,
            'actual_gold' => $gold,
            'charged_amount' => $requested,
            'unused_amount' => 0,
            'minimum_amount' => 5000,
            'next_bar_amount' => 0,
        ];
    }

    private function normalizePositiveInteger(int|string $value, string $message): string
    {
        $normalized = ltrim((string) $value, '+');

        if ($normalized === '' || ! ctype_digit($normalized) || bccomp($normalized, '0') <= 0) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private function toInteger(string $value): int
    {
        if (bccomp($value, (string) PHP_INT_MAX) > 0) {
            throw new InvalidArgumentException('Giá trị giao dịch vượt giới hạn hệ thống.');
        }

        return (int) $value;
    }
}
