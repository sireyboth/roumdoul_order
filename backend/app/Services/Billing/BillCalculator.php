<?php

namespace App\Services\Billing;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Bill arithmetic and nothing else: no database, no clock, so every rule is
 * easy to unit-test. Order: subtotal → discounts → service charge → VAT.
 *
 * - Discounts apply one after another to what is left, and never go below zero.
 * - Service charge is a percentage of the discounted subtotal.
 * - VAT is charged on the discounted subtotal plus service charge. When menu
 *   prices already include VAT nothing is added (`vat` = 0) and the VAT inside
 *   the total is reported as `vatIncluded` for receipts.
 * - Percentages are basis points (1000 = 10%); each step rounds half up to a
 *   whole minor unit.
 */
final class BillCalculator
{
    /**
     * @param  array<int, array{type:string, value:int}>  $adjustments
     */
    public static function calculate(
        int $subtotal,
        array $adjustments,
        int $serviceChargeBp,
        int $vatBp,
        bool $pricesIncludeVat,
        string $currency,
        int $khrPerUsd,
    ): BillTotals {
        if ($subtotal < 0 || $serviceChargeBp < 0 || $vatBp < 0 || $khrPerUsd < 1) {
            throw new InvalidArgumentException('Bill inputs must not be negative.');
        }

        $remaining = $subtotal;
        $amounts = [];

        foreach ($adjustments as $adjustment) {
            $value = (int) $adjustment['value'];

            $amount = match ($adjustment['type']) {
                'percent' => self::percentOf($remaining, min($value, 10000)),
                'fixed' => $value,
                default => throw new InvalidArgumentException("Unknown discount type {$adjustment['type']}."),
            };

            $amount = max(0, min($amount, $remaining));
            $amounts[] = $amount;
            $remaining -= $amount;
        }

        $serviceCharge = self::percentOf($remaining, $serviceChargeBp);
        $taxable = $remaining + $serviceCharge;

        if ($pricesIncludeVat) {
            $vat = 0;
            $total = $taxable;
            $vatIncluded = self::includedVat($taxable, $vatBp);
        } else {
            $vat = self::percentOf($taxable, $vatBp);
            $total = $taxable + $vat;
            $vatIncluded = $vat;
        }

        return new BillTotals(
            subtotal: $subtotal,
            discountTotal: $subtotal - $remaining,
            adjustmentAmounts: $amounts,
            serviceCharge: $serviceCharge,
            vat: $vat,
            vatIncluded: $vatIncluded,
            total: $total,
            totalKhr: Money::toRiel($total, $currency, $khrPerUsd),
        );
    }

    /** The VAT already inside a VAT-inclusive amount: amount × rate / (1 + rate), halves rounded up. */
    public static function includedVat(int $amount, int $vatBp): int
    {
        return $vatBp > 0 ? intdiv($amount * $vatBp * 2 + (10000 + $vatBp), 2 * (10000 + $vatBp)) : 0;
    }

    /** $amount × $bp / 10000, halves rounded up, using integers only. */
    public static function percentOf(int $amount, int $bp): int
    {
        return intdiv($amount * $bp + 5000, 10000);
    }
}
