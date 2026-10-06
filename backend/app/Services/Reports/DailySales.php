<?php

namespace App\Services\Reports;

use App\Enums\BillStatus;
use App\Enums\OrderStatus;
use App\Jobs\RebuildDailySales;
use App\Models\Bill;
use App\Models\Branch;
use App\Models\DailyBranchSale;
use App\Models\DailyItemSale;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * The report copy (daily_branch_sales, daily_item_sales) for one branch-day,
 * always recomputed in full from the raw rows, so it can never drift and can
 * be rebuilt at any time.
 *
 * Sales are paid bills, counted on the bill's business date. A refund made
 * later is counted on the same day as the sale it gives back.
 */
class DailySales
{
    /** Ask for a rebuild once the current request has answered (no queue worker needed). */
    public static function queue(int $branchId, string $date): void
    {
        DB::afterCommit(fn () => RebuildDailySales::dispatch($branchId, $date)->afterResponse());
    }

    public function rebuild(int $branchId, string $date): DailyBranchSale
    {
        $branch = Branch::withTrashed()->with('company')->findOrFail($branchId);

        return DB::transaction(function () use ($branch, $date) {
            $orders = Order::query()->where('branch_id', $branch->id)->whereDate('business_date', $date);

            $bills = Bill::query()
                ->where('branch_id', $branch->id)
                ->whereDate('business_date', $date)
                ->where('status', BillStatus::Paid->value)
                ->get(['id', 'table_session_id', 'subtotal', 'discount_total', 'service_charge', 'vat', 'total']);

            $payments = Payment::query()->whereIn('bill_id', $bills->pluck('id'))->get(['method', 'amount', 'status']);
            $confirmed = $payments->where('status', 'confirmed');
            $byMethod = fn (string $method) => (int) $confirmed->filter(fn (Payment $p) => $p->method->value === $method)->sum('amount');

            $items = OrderItem::query()
                ->whereHas('order', fn ($q) => $q
                    ->whereIn('table_session_id', $bills->pluck('table_session_id'))
                    ->where('status', '!=', OrderStatus::Cancelled->value))
                ->get(['menu_item_id', 'name_en', 'name_km', 'quantity', 'line_total'])
                ->groupBy(fn (OrderItem $item) => $item->menu_item_id ?? 'deleted:'.$item->name_en);

            $row = DailyBranchSale::query()
                ->where('branch_id', $branch->id)
                ->whereDate('business_date', $date)
                ->first()
                ?? new DailyBranchSale(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'business_date' => $date]);

            $row->fill([
                'currency' => $branch->company->currency,
                'orders_count' => (clone $orders)->where('status', '!=', OrderStatus::Cancelled->value)->count(),
                'cancelled_count' => (clone $orders)->where('status', OrderStatus::Cancelled->value)->count(),
                'bills_count' => $bills->count(),
                'items_count' => (int) $items->sum(fn ($group) => $group->sum('quantity')),
                'gross' => (int) $bills->sum('subtotal'),
                'discounts' => (int) $bills->sum('discount_total'),
                'service_charge' => (int) $bills->sum('service_charge'),
                'vat' => (int) $bills->sum('vat'),
                'net' => (int) $bills->sum('total'),
                'refunds' => (int) $payments->where('status', 'refunded')->sum('amount'),
                'cash' => $byMethod('cash'),
                'khqr' => $byMethod('khqr'),
                'card' => $byMethod('card'),
                'other' => $byMethod('other'),
            ])->save();

            DailyItemSale::query()->where('branch_id', $branch->id)->whereDate('business_date', $date)->delete();

            foreach ($items as $group) {
                $first = $group->first();
                DailyItemSale::query()->create([
                    'company_id' => $branch->company_id,
                    'branch_id' => $branch->id,
                    'business_date' => $date,
                    'menu_item_id' => $first->menu_item_id,
                    'name_en' => $first->name_en,
                    'name_km' => $first->name_km,
                    'quantity' => (int) $group->sum('quantity'),
                    'amount' => (int) $group->sum('line_total'),
                ]);
            }

            return $row;
        });
    }

    /**
     * Every branch-day that has orders or bills, optionally limited to a date range.
     *
     * @return array<int, array{branch_id:int, date:string}>
     */
    public static function daysWithActivity(?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        $collect = function (string $table) use ($from, $to, $branchId) {
            return DB::table($table)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->when($from, fn ($q) => $q->whereDate('business_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('business_date', '<=', $to))
                ->select('branch_id', 'business_date')
                ->distinct()
                ->get();
        };

        return $collect('orders')->concat($collect('bills'))
            ->map(fn ($r) => ['branch_id' => (int) $r->branch_id, 'date' => substr((string) $r->business_date, 0, 10)])
            ->unique(fn ($r) => $r['branch_id'].'|'.$r['date'])
            ->sortBy('date')
            ->values()
            ->all();
    }
}
