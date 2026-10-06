<?php

namespace App\Services\Billing;

/** The result of BillCalculator. Every amount is in minor units of the bill currency, except total_khr. */
final readonly class BillTotals
{
    /** @param  array<int, int>  $adjustmentAmounts  what each discount took off, in input order */
    public function __construct(
        public int $subtotal,
        public int $discountTotal,
        public array $adjustmentAmounts,
        public int $serviceCharge,
        public int $vat,
        public int $vatIncluded,
        public int $total,
        public int $totalKhr,
    ) {}
}
