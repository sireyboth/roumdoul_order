<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_usd_is_stored_in_cents_without_float_drift(): void
    {
        $this->assertSame(150, Money::toMinor('1.50', 'USD'));
        $this->assertSame(1999, Money::toMinor(19.99, 'USD'));
        $this->assertSame(30, Money::toMinor(0.3, 'USD'));
        $this->assertSame(1.5, Money::fromMinor(150, 'USD'));
        $this->assertSame('$2.25', Money::format(225, 'USD'));
    }

    public function test_riel_has_no_minor_unit(): void
    {
        $this->assertSame(6000, Money::toMinor('6000', 'KHR'));
        $this->assertSame(6000, Money::fromMinor(6000, 'KHR'));
        $this->assertSame('6,000៛', Money::format(6000, 'KHR'));
    }

    public function test_empty_input_stays_empty(): void
    {
        $this->assertNull(Money::toMinor('', 'USD'));
        $this->assertNull(Money::toMinor(null, 'USD'));
    }
}
