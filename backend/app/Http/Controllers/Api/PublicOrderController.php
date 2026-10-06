<?php

namespace App\Http\Controllers\Api;

use App\Enums\BillStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\ServiceRequest;
use App\Models\TableSession;
use App\Services\MenuBuilder;
use App\Services\Ordering\OrderPlacer;
use App\Services\Ordering\OrderPresenter;
use App\Services\Ordering\ServiceRequests;
use App\Services\TelegramNotifier;
use App\Support\Live;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Customer actions at a table. The QR token is the only identity. */
class PublicOrderController extends Controller
{
    public function __construct(private MenuBuilder $menus) {}

    private function table(string $token): DiningTable
    {
        return $this->menus->resolveTable($token)
            ?? abort(response()->json(['message' => 'This QR code is not active.'], 404));
    }

    public function store(Request $request, string $token, OrderPlacer $placer): JsonResponse
    {
        $table = $this->table($token);

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

        $order = $placer->place($table, $data['items'], $data['note'] ?? null, $data['idempotency_key']);

        if (! $existed) {
            dispatch(fn () => app(TelegramNotifier::class)->newOrder($order))->afterResponse();
        }

        return response()->json(['data' => OrderPresenter::order($order)], $existed ? 200 : 201);
    }

    /** Everything this table ordered in the current visit, for the tracking screen. */
    public function session(string $token): JsonResponse
    {
        $table = $this->table($token);
        $session = DiningTable::query()->find($table->id)?->openSession();

        if (! $session) {
            return response()->json(['data' => [
                'status' => 'none',
                'orders' => [],
                'requests' => [],
                'subtotal' => 0,
                'bill' => null,
                // Lets the phone that was part of the last visit say "Paid, thank you".
                // Only the outcome, never the orders: the next customer may already be at the table.
                'last_visit' => $this->lastVisit($table->id),
                'live_channel' => Live::enabled() ? Live::tableChannel($table->id) : null,
            ]])->header('Cache-Control', 'no-store');
        }

        $orders = $session->orders()->with('items')->latest('id')->get();
        $requests = $session->serviceRequests()->where('status', 'open')->get();
        $bill = $session->bill;

        return response()->json(['data' => [
            'status' => $session->status,
            'subtotal' => $orders->where('status', '!=', OrderStatus::Cancelled)->sum('subtotal'),
            'orders' => $orders->map(fn (Order $o) => OrderPresenter::order($o))->values(),
            'requests' => $requests->map(fn (ServiceRequest $r) => ['type' => $r->type, 'created_at' => $r->created_at?->toIso8601String()])->values(),
            // Once the cashier opens the bill: the real total with discounts, service charge and VAT.
            'bill' => $bill ? [
                'number' => $bill->number,
                'total' => $bill->total,
                'total_khr' => $bill->total_khr,
                'paid_total' => $bill->paid_total,
                'discount_total' => $bill->discount_total,
                'service_charge' => $bill->service_charge,
                'vat' => $bill->vat,
            ] : null,
            'last_visit' => null,
            'live_channel' => Live::enabled() ? Live::tableChannel($table->id) : null,
        ]])->header('Cache-Control', 'no-store');
    }

    /** @return array{result:string, closed_at:string}|null */
    private function lastVisit(int $tableId): ?array
    {
        $last = TableSession::query()
            ->where('dining_table_id', $tableId)
            ->where('status', 'closed')
            ->where('closed_at', '>=', now()->subMinutes(15))
            ->latest('closed_at')
            ->with('bill')
            ->first();

        if (! $last || $last->bill?->status !== BillStatus::Paid) {
            return null;
        }

        return ['result' => 'paid', 'closed_at' => $last->closed_at->toIso8601String()];
    }

    public function requestService(Request $request, string $token, ServiceRequests $service): JsonResponse
    {
        $table = DiningTable::query()->findOrFail($this->table($token)->id);

        $data = $request->validate(['type' => ['required', Rule::in(ServiceRequest::TYPES)]]);

        $serviceRequest = $service->open($table, $data['type']);

        return response()->json(['data' => ['type' => $serviceRequest->type, 'status' => $serviceRequest->status]], 201);
    }
}
