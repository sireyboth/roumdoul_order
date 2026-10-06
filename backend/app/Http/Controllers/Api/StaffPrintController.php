<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillAdjustment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\Billing\BillCalculator;
use App\Services\Billing\BillService;
use App\Services\Ordering\OrderPresenter;
use App\Support\Money;
use App\Support\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** What the 80 mm print pages need: the customer's bill / receipt and kitchen or bar tickets. */
class StaffPrintController extends Controller
{
    /** Waiters print the bill to bring it to the table; cashiers print receipts. */
    private const BILL_PRINTERS = [StaffRole::Owner, StaffRole::Manager, StaffRole::Cashier, StaffRole::Waiter];

    public function receipt(Request $request, Bill $bill, BillService $bills): JsonResponse
    {
        StaffAccess::authorize($request->user(), $bill->branch, self::BILL_PRINTERS);

        if ($bill->isOpen()) {
            $bill = $bills->refresh($bill);
        }

        $bill->load(['branch.company', 'session.table.area', 'activeAdjustments', 'payments.receivedBy']);
        $branch = $bill->branch;
        $company = $branch->company;

        $items = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('table_session_id', $bill->table_session_id)->where('status', '!=', OrderStatus::Cancelled->value))
            ->orderBy('id')
            ->get();

        $confirmed = $bill->payments->where('status', 'confirmed');

        return response()->json(['data' => [
            'company' => [
                'name' => $company->name,
                'logo_url' => $company->logo_path ? Storage::disk('public')->url($company->logo_path) : null,
                'khqr_url' => $company->khqr_image_path ? Storage::disk('public')->url($company->khqr_image_path) : null,
            ],
            'branch' => [
                'name' => $branch->name,
                'address' => $branch->address,
                'phone' => $branch->phone,
                'header' => $branch->receipt_header,
                'footer' => $branch->receipt_footer,
            ],
            'bill' => [
                'number' => $bill->number,
                'status' => $bill->status->value,
                'business_date' => $bill->business_date?->toDateString(),
                'opened_at' => $bill->created_at?->toIso8601String(),
                'paid_at' => $bill->paid_at?->toIso8601String(),
                'table' => $bill->session?->table?->name,
                'area' => $bill->session?->table?->area?->name,
                'currency' => $bill->currency,
                'khr_per_usd' => $bill->khr_per_usd,
                'subtotal' => $bill->subtotal,
                'adjustments' => $bill->activeAdjustments->map(fn (BillAdjustment $a) => [
                    'label' => $a->type === 'percent' ? 'Discount '.rtrim(rtrim(number_format($a->value / 100, 2), '0'), '.').'%' : 'Discount',
                    'amount' => $a->amount,
                ])->values()->all(),
                'discount_total' => $bill->discount_total,
                'service_charge' => $bill->service_charge,
                'service_charge_bp' => $bill->service_charge_bp,
                'vat' => $bill->vat,
                'vat_bp' => $bill->vat_bp,
                'prices_include_vat' => $bill->prices_include_vat,
                'vat_included' => $bill->prices_include_vat ? BillCalculator::includedVat($bill->total, $bill->vat_bp) : $bill->vat,
                'total' => $bill->total,
                'total_khr' => $bill->total_khr,
                'paid_total' => $bill->paid_total,
                'remaining' => $bill->remaining(),
                'remaining_khr' => Money::toRiel($bill->remaining(), $bill->currency, $bill->khr_per_usd),
            ],
            'lines' => self::lines($items),
            'payments' => $confirmed->map(fn (Payment $p) => [
                'method' => $p->method->value,
                'amount' => $p->amount,
                'tendered_amount' => $p->tendered_amount,
                'tendered_currency' => $p->tendered_currency,
                'change_amount' => $p->change_amount,
                'change_currency' => $p->change_currency,
                'reference' => $p->reference,
            ])->values()->all(),
            'cashier' => $confirmed->last()?->receivedBy?->name ?? $request->user()->name,
            'printed_at' => now()->toIso8601String(),
        ]])->header('Cache-Control', 'no-store');
    }

    public function ticket(Request $request, Order $order): JsonResponse
    {
        StaffAccess::authorize($request->user(), $order->branch);

        $data = $request->validate(['station' => ['nullable', Rule::in(['kitchen', 'bar'])]]);
        $row = OrderPresenter::order($order->load(['items', 'table.area']), forStaff: true);

        if ($station = $data['station'] ?? null) {
            $row['items'] = array_values(array_filter($row['items'], fn ($i) => $i['station'] === $station));
        }

        return response()->json(['data' => $row + [
            'station' => $data['station'] ?? null,
            'branch' => $order->branch->name,
            'printed_at' => now()->toIso8601String(),
        ]])->header('Cache-Control', 'no-store');
    }

    /**
     * Receipt lines: the same item with the same choices and price, ordered in
     * several rounds, prints as one line ("3 × Iced latte").
     *
     * @param  iterable<OrderItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private static function lines(iterable $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $options = collect($item->options ?? [])->map(fn ($o) => ['km' => $o['name_km'], 'en' => $o['name_en']])->values()->all();
            $key = implode('|', [$item->menu_item_id, $item->unit_price, $item->note, json_encode($options)]);

            if (isset($lines[$key])) {
                $lines[$key]['quantity'] += $item->quantity;
                $lines[$key]['line_total'] += $item->line_total;

                continue;
            }

            $lines[$key] = [
                'name' => ['km' => $item->name_km, 'en' => $item->name_en],
                'options' => $options,
                'note' => $item->note,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
            ];
        }

        return array_values($lines);
    }
}
