<?php

namespace App\Services\Reports;

use App\Models\Company;
use App\Models\DailyItemSale;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Support\Money;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spreadsheet exports for owners: orders, payments, items sold. CSV with a UTF-8
 * byte-order mark, so Excel opens Khmer text correctly. Amounts are plain numbers
 * in the company currency (5.25, not "$5.25") so they can be summed in Excel.
 * Every query is limited to one company.
 */
class CsvExport
{
    public const TYPES = ['orders' => 'Orders', 'payments' => 'Payments', 'items' => 'Items sold'];

    public function __construct(private Company $company) {}

    public function download(string $type, string $from, string $to, ?int $branchId = null): StreamedResponse
    {
        $rows = match ($type) {
            'orders' => $this->orders($from, $to, $branchId),
            'payments' => $this->payments($from, $to, $branchId),
            'items' => $this->items($from, $to, $branchId),
        };

        $name = "{$this->company->slug}-{$type}-{$from}-to-{$to}.csv";

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return iterable<array<int, mixed>> */
    public function orders(string $from, string $to, ?int $branchId = null): iterable
    {
        yield ['Business day', 'Order', 'Time', 'Branch', 'Table', 'Status', 'Taken by', 'Items', 'Amount', 'Currency', 'Cancel reason'];

        $query = Order::query()
            ->where('company_id', $this->company->id)
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['items', 'branch', 'table'])
            ->orderBy('business_date')
            ->orderBy('number');

        foreach ($query->lazy(500) as $order) {
            yield [
                $order->business_date->toDateString(),
                $order->number,
                $order->created_at?->timezone($this->company->timezone)->format('Y-m-d H:i'),
                $order->branch?->name,
                $order->table?->name,
                $order->status->value,
                $order->source === 'waiter' ? 'waiter' : 'customer QR',
                $order->items->map(fn (OrderItem $i) => "{$i->quantity} x {$i->name_en}")->implode('; '),
                Money::fromMinor($order->subtotal, $order->currency),
                $order->currency,
                $order->cancel_reason,
            ];
        }
    }

    /** @return iterable<array<int, mixed>> */
    public function payments(string $from, string $to, ?int $branchId = null): iterable
    {
        yield ['Business day', 'Paid at', 'Branch', 'Bill', 'Table', 'Method', 'Amount', 'Currency', 'Cash given', 'Given in', 'Change', 'Change in', 'Reference', 'Status', 'Cashier', 'Refund reason'];

        $query = Payment::query()
            ->where('company_id', $this->company->id)
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['branch', 'bill.session.table', 'receivedBy'])
            ->orderBy('paid_at');

        foreach ($query->lazy(500) as $payment) {
            $currency = $payment->bill->currency;

            yield [
                $payment->business_date->toDateString(),
                $payment->paid_at->timezone($this->company->timezone)->format('Y-m-d H:i'),
                $payment->branch?->name,
                $payment->bill->number,
                $payment->bill->session?->table?->name,
                $payment->method->getLabel(),
                Money::fromMinor($payment->amount, $currency),
                $currency,
                $payment->tendered_currency ? Money::fromMinor($payment->tendered_amount, $payment->tendered_currency) : null,
                $payment->tendered_currency,
                $payment->change_currency ? Money::fromMinor($payment->change_amount, $payment->change_currency) : null,
                $payment->change_currency,
                $payment->reference,
                $payment->status,
                $payment->receivedBy?->name,
                $payment->refund_reason,
            ];
        }
    }

    /** @return iterable<array<int, mixed>> */
    public function items(string $from, string $to, ?int $branchId = null): iterable
    {
        yield ['Business day', 'Branch', 'Item', 'Item (Khmer)', 'Quantity', 'Amount', 'Currency'];

        $query = DailyItemSale::query()
            ->where('company_id', $this->company->id)
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('branch')
            ->orderBy('business_date')
            ->orderByDesc('quantity');

        foreach ($query->lazy(500) as $row) {
            yield [
                $row->business_date->toDateString(),
                $row->branch?->name,
                $row->name_en,
                $row->name_km,
                $row->quantity,
                Money::fromMinor($row->amount, $this->company->currency),
                $this->company->currency,
            ];
        }
    }
}
