<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillAdjustment;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TableSession;
use App\Services\Billing\BillPresenter;
use App\Services\Billing\BillService;
use App\Services\MenuBuilder;
use App\Services\Ordering\OrderPlacer;
use App\Services\Ordering\OrderPresenter;
use App\Services\TelegramNotifier;
use App\Support\Money;
use App\Support\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The cashier screen (tables, bills, payments) and the waiter's "New order for a table". */
class StaffCashierController extends Controller
{
    /** Who may take money, give discounts (with a PIN), void and refund. */
    private const CASHIER = [StaffRole::Owner, StaffRole::Manager, StaffRole::Cashier];

    /** Who may type in an order for a table. */
    private const ORDER_TAKERS = [StaffRole::Owner, StaffRole::Manager, StaffRole::Cashier, StaffRole::Waiter];

    public function __construct(private BillService $bills) {}

    /** Every table of the branch with its open visit and what it owes so far. Polled by the cashier screen. */
    public function tables(Request $request, Branch $branch): JsonResponse
    {
        StaffAccess::authorize($request->user(), $branch);

        $sessions = TableSession::query()
            ->where('branch_id', $branch->id)
            ->where('status', '!=', 'closed')
            ->with(['bill', 'branch.company'])
            ->withCount([
                'orders as orders_count' => fn ($q) => $q->where('status', '!=', OrderStatus::Cancelled->value),
                'orders as active_orders_count' => fn ($q) => $q->whereIn('status', ['placed', 'accepted', 'preparing', 'ready']),
            ])
            ->get()
            ->keyBy('dining_table_id');

        $tables = DiningTable::query()
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->with('area')
            ->get()
            ->sortBy(fn (DiningTable $t) => [$t->area?->sort_order ?? 999, $t->sort_order, $t->name])
            ->map(function (DiningTable $table) use ($sessions) {
                $session = $sessions->get($table->id);

                return [
                    'id' => $table->id,
                    'name' => $table->name,
                    'area' => $table->area?->name,
                    'session' => $session ? $this->sessionSummary($session) : null,
                ];
            })
            ->values();

        return response()->json(['data' => [
            'server_time' => now()->toIso8601String(),
            'currency' => $branch->company->currency,
            'khr_per_usd' => $branch->company->khr_per_usd,
            'tables' => $tables,
        ]])->header('Cache-Control', 'no-store');
    }

    /** Opens (or returns) the bill for a table visit. */
    public function openBill(Request $request, TableSession $session): JsonResponse
    {
        StaffAccess::authorize($request->user(), $session->branch, self::CASHIER);

        $bill = $this->bills->openFor($session, $request->user());

        return response()->json(['data' => BillPresenter::bill($bill->fresh())]);
    }

    public function showBill(Request $request, Bill $bill): JsonResponse
    {
        StaffAccess::authorize($request->user(), $bill->branch, self::CASHIER);

        if ($bill->isOpen()) {
            $bill = $this->bills->refresh($bill);
        }

        return response()->json(['data' => BillPresenter::bill($bill)])->header('Cache-Control', 'no-store');
    }

    public function addDiscount(Request $request, Bill $bill): JsonResponse
    {
        StaffAccess::authorize($request->user(), $bill->branch, self::CASHIER);

        $data = $request->validate([
            'type' => ['required', Rule::in(BillAdjustment::TYPES)],
            // percent: 0.5–100 (sent as typed); fixed: an amount in the bill currency as typed (e.g. 1.50)
            'value' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'reason' => ['required', 'string', 'max:255'],
            'pin' => ['required', 'string', 'max:6'],
        ]);

        $value = $data['type'] === 'percent'
            ? (int) round(((float) $data['value']) * 100)
            : (int) Money::toMinor($data['value'], $bill->currency);

        $this->bills->addDiscount($bill, $data['type'], $value, $data['reason'], $data['pin'], $request->user());

        return response()->json(['data' => BillPresenter::bill($bill->fresh())]);
    }

    public function removeDiscount(Request $request, Bill $bill, BillAdjustment $adjustment): JsonResponse
    {
        StaffAccess::authorize($request->user(), $bill->branch, self::CASHIER);
        abort_unless($adjustment->bill_id === $bill->id, 404);

        $data = $request->validate(['pin' => ['required', 'string', 'max:6']]);

        $this->bills->removeDiscount($adjustment, $data['pin'], $request->user());

        return response()->json(['data' => BillPresenter::bill($bill->fresh())]);
    }

    public function pay(Request $request, Bill $bill): JsonResponse
    {
        StaffAccess::authorize($request->user(), $bill->branch, self::CASHIER);

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            // Minor units of the bill currency (cents or riel), for KHQR / card / other.
            'amount' => ['nullable', 'integer', 'min:1'],
            // Cash only: what was handed over, in minor units of tendered_currency.
            'tendered_amount' => ['required_if:method,cash', 'nullable', 'integer', 'min:1', 'max:1000000000'],
            'tendered_currency' => ['required_if:method,cash', 'nullable', Rule::in(['USD', 'KHR'])],
            'change_currency' => ['nullable', Rule::in(['USD', 'KHR'])],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $payment = $this->bills->addPayment($bill, $data, $request->user());

        return response()->json(['data' => [
            'payment' => BillPresenter::payment($payment),
            'bill' => BillPresenter::bill($bill->fresh()),
        ]], $payment->wasRecentlyCreated ? 201 : 200);
    }

    public function void(Request $request, Bill $bill): JsonResponse
    {
        StaffAccess::authorize($request->user(), $bill->branch, self::CASHIER);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'pin' => ['required', 'string', 'max:6'],
        ]);

        $this->bills->void($bill, $data['reason'], $data['pin'], $request->user());

        return response()->json(['data' => BillPresenter::bill($bill->fresh())]);
    }

    public function refund(Request $request, Payment $payment): JsonResponse
    {
        StaffAccess::authorize($request->user(), $payment->branch, self::CASHIER);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'pin' => ['required', 'string', 'max:6'],
        ]);

        $this->bills->refund($payment, $data['reason'], $data['pin'], $request->user());

        return response()->json(['data' => BillPresenter::bill($payment->bill()->firstOrFail())]);
    }

    /** The same menu customers see, for the waiter's "New order" screen. */
    public function orderMenu(Request $request, Branch $branch, MenuBuilder $menus): JsonResponse
    {
        StaffAccess::authorize($request->user(), $branch, self::ORDER_TAKERS);

        $company = $branch->company;

        return response()->json(['data' => [
            'currency' => $company->currency,
            'khr_per_usd' => $company->khr_per_usd,
            'menu_version' => $company->menu_version.'.'.$branch->menu_version,
            'categories' => $menus->branchMenu($branch),
        ]])->header('Cache-Control', 'no-store');
    }

    /** A waiter types in an order for a table. Same checks and prices as a customer order. */
    public function placeOrder(Request $request, Branch $branch, DiningTable $table, OrderPlacer $placer): JsonResponse
    {
        StaffAccess::authorize($request->user(), $branch, self::ORDER_TAKERS);
        abort_unless($table->branch_id === $branch->id && $table->is_active, 404);

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:'.OrderPlacer::MAX_LINES],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.OrderPlacer::MAX_QUANTITY],
            'items.*.option_ids' => ['array', 'max:20'],
            'items.*.option_ids.*' => ['integer'],
            'items.*.note' => ['nullable', 'string', 'max:120'],
        ]);

        $existed = OrderPlacer::existing($table, $data['idempotency_key']) !== null;

        $order = $placer->place($table, $data['items'], $data['note'] ?? null, $data['idempotency_key'], $request->user());

        if (! $existed) {
            dispatch(fn () => app(TelegramNotifier::class)->newOrder($order))->afterResponse();
        }

        return response()->json(['data' => OrderPresenter::order($order->load('table.area'), forStaff: true)], $existed ? 200 : 201);
    }

    /** @return array<string, mixed> */
    private function sessionSummary(TableSession $session): array
    {
        $bill = $session->bill;
        $preview = $bill && ! $bill->isOpen() ? null : BillService::preview($session);

        return [
            'id' => $session->id,
            'status' => $session->status,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'bill_requested_at' => $session->bill_requested_at?->toIso8601String(),
            'orders_count' => $session->orders_count,
            'active_orders_count' => $session->active_orders_count,
            'total' => $preview?->total ?? $bill?->total ?? 0,
            'paid_total' => $bill?->paid_total ?? 0,
            'bill' => $bill ? ['id' => $bill->id, 'number' => $bill->number, 'status' => $bill->status->value] : null,
        ];
    }
}
