<?php

namespace App\Services\Reports;

use App\Models\Branch;
use App\Models\Company;
use App\Models\DailyBranchSale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Read-only numbers for one company's dashboard, from the report copy. Always scoped to that company. */
class SalesReport
{
    private const FIELDS = ['net', 'gross', 'discounts', 'refunds', 'bills_count', 'orders_count', 'cancelled_count', 'items_count', 'cash', 'khqr', 'card', 'other'];

    public function __construct(private Company $company) {}

    /** "Today" for the company: the latest business date among its branches (each branch has its own cutoff). */
    public function today(): string
    {
        $dates = $this->company->branches()->get()
            ->map(fn (Branch $b) => $b->setRelation('company', $this->company)->businessDate());

        return $dates->max() ?? now($this->company->timezone)->toDateString();
    }

    /**
     * Totals per business date between two dates (inclusive); every date is present, even with no sales.
     *
     * @return Collection<string, array<string, int>>
     */
    public function days(string $from, string $to): Collection
    {
        $rows = DailyBranchSale::query()
            ->where('company_id', $this->company->id)
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->get()
            ->groupBy(fn (DailyBranchSale $r) => $r->business_date->toDateString());

        $days = collect();
        for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
            $group = $rows->get($d->toDateString(), collect());
            $days[$d->toDateString()] = collect(self::FIELDS)
                ->mapWithKeys(fn ($f) => [$f => (int) $group->sum($f)])
                ->all();
        }

        return $days;
    }

    /** @return array<string, int> */
    public function total(string $from, string $to): array
    {
        $days = $this->days($from, $to);

        return collect(self::FIELDS)->mapWithKeys(fn ($f) => [$f => (int) $days->sum($f)])->all();
    }
}
