<?php

namespace Tests\Unit;

use App\Services\Billing\BillCalculator;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class BillCalculatorTest extends TestCase
{
    public function test_nothing_extra_when_no_charges(): void
    {
        $t = BillCalculator::calculate(525, [], 0, 0, true, 'USD', 4100);

        $this->assertSame(525, $t->subtotal);
        $this->assertSame(0, $t->discountTotal);
        $this->assertSame(0, $t->serviceCharge);
        $this->assertSame(0, $t->vat);
        $this->assertSame(525, $t->total);
        $this->assertSame(21500, $t->totalKhr); // 525 × 41 = 21,525 → 21,500
    }

    public function test_vat_added_on_top_includes_the_service_charge(): void
    {
        // $10.00, 10% service, 10% VAT not in menu prices
        $t = BillCalculator::calculate(1000, [], 1000, 1000, false, 'USD', 4100);

        $this->assertSame(100, $t->serviceCharge);
        $this->assertSame(110, $t->vat); // 10% of 11.00
        $this->assertSame(110, $t->vatIncluded);
        $this->assertSame(1210, $t->total);
        $this->assertSame(49600, $t->totalKhr); // 1210 × 41 = 49,610 → 49,600
    }

    public function test_vat_inside_menu_prices_is_not_added_again(): void
    {
        $t = BillCalculator::calculate(1100, [], 0, 1000, true, 'USD', 4100);

        $this->assertSame(0, $t->vat);
        $this->assertSame(1100, $t->total);
        $this->assertSame(100, $t->vatIncluded); // 11.00 × 10 / 110
    }

    public function test_discounts_apply_in_order_before_service_and_vat(): void
    {
        $t = BillCalculator::calculate(1250, [
            ['type' => 'percent', 'value' => 1000], // 10% of 12.50 = 1.25
            ['type' => 'fixed', 'value' => 200],    // then 2.00 off
        ], 1000, 0, true, 'USD', 4100);

        $this->assertSame([125, 200], $t->adjustmentAmounts);
        $this->assertSame(325, $t->discountTotal);
        $this->assertSame(93, $t->serviceCharge); // 10% of 9.25 = 0.925 → 0.93
        $this->assertSame(1018, $t->total);
    }

    public function test_percent_rounds_half_up_to_a_whole_cent(): void
    {
        $this->assertSame(50, BillCalculator::percentOf(333, 1500)); // 49.95
        $this->assertSame(1, BillCalculator::percentOf(5, 1000));    // 0.5
        $this->assertSame(0, BillCalculator::percentOf(4, 1000));    // 0.4
    }

    public function test_discount_never_makes_the_bill_negative(): void
    {
        $t = BillCalculator::calculate(500, [
            ['type' => 'fixed', 'value' => 800],
            ['type' => 'percent', 'value' => 5000],
        ], 1000, 1000, false, 'USD', 4100);

        $this->assertSame([500, 0], $t->adjustmentAmounts);
        $this->assertSame(0, $t->total);
        $this->assertSame(0, $t->totalKhr);
    }

    public function test_riel_bills_round_to_100(): void
    {
        $this->assertSame(18500, BillCalculator::calculate(18450, [], 0, 0, true, 'KHR', 4100)->totalKhr);
        $this->assertSame(18400, BillCalculator::calculate(18449, [], 0, 0, true, 'KHR', 4100)->totalKhr);
        $this->assertSame(18450, BillCalculator::calculate(18450, [], 0, 0, true, 'KHR', 4100)->total);
    }

    public function test_riel_conversions(): void
    {
        $this->assertSame(18500, Money::toRiel(450, 'USD', 4100)); // 18,450
        $this->assertSame(450, Money::fromRiel(18450, 'USD', 4100));
        $this->assertSame(487, Money::fromRiel(20000, 'USD', 4100)); // 4.878… rounds down
        $this->assertSame(20500, Money::fromUsdCents(500, 'KHR', 4100));
        $this->assertSame(0, Money::roundRiel(49));
        $this->assertSame(100, Money::roundRiel(50));
    }
}
