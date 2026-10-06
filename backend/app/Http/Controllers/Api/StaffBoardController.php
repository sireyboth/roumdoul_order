<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchMenuItem;
use App\Models\Order;
use App\Models\ServiceRequest;
use App\Services\Ordering\OrderPresenter;
use App\Services\Ordering\ServiceRequests;
use App\Support\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** What the kitchen and waiter screens read and change. */
class StaffBoardController extends Controller
{
    /** Active orders and open table calls for one branch. Polled every few seconds. */
    public function board(Request $request, Branch $branch): JsonResponse
    {
        StaffAccess::authorize($request->user(), $branch);

        $station = $request->query('station');

        $orders = Order::query()
            ->where('branch_id', $branch->id)
            ->where(function ($q) {
                $q->whereIn('status', ['placed', 'accepted', 'preparing', 'ready'])
                    // Keep just-served orders on screen briefly so a mis-tap can be seen.
                    ->orWhere(fn ($q) => $q->where('status', 'served')->where('served_at', '>=', now()->subMinutes(10)));
            })
            ->with(['items', 'table.area'])
            ->orderBy('id')
            ->limit(200)
            ->get();

        $data = $orders->map(function (Order $order) use ($station) {
            $row = OrderPresenter::order($order, forStaff: true);

            if (in_array($station, ['kitchen', 'bar'], true)) {
                $row['items'] = array_values(array_filter($row['items'], fn ($i) => $i['station'] === $station));
            }

            return $row;
        })->filter(fn ($row) => $row['items'] !== [])->values();

        $requests = ServiceRequest::query()
            ->where('branch_id', $branch->id)
            ->where('status', 'open')
            ->with('table')
            ->orderBy('id')
            ->get()
            ->map(fn (ServiceRequest $r) => OrderPresenter::request($r))
            ->values();

        return response()->json(['data' => [
            'server_time' => now()->toIso8601String(),
            'settings' => ['auto_print_kitchen' => (bool) $branch->auto_print_kitchen],
            'orders' => $data,
            'requests' => $requests,
        ]])->header('Cache-Control', 'no-store');
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $membership = StaffAccess::authorize($request->user(), $order->branch);

        $data = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $next = OrderStatus::from($data['status']);

        if ($next === OrderStatus::Cancelled) {
            abort_unless(in_array($membership->role, [StaffRole::Owner, StaffRole::Manager, StaffRole::Cashier], true), 403, 'Only a cashier or manager can cancel orders.');
        }

        $order->moveTo($next, $request->user(), $data['reason'] ?? null);

        return response()->json(['data' => OrderPresenter::order($order->fresh(['items', 'table.area']), forStaff: true)]);
    }

    public function resolveRequest(Request $request, ServiceRequest $serviceRequest, ServiceRequests $service): JsonResponse
    {
        StaffAccess::authorize($request->user(), Branch::query()->findOrFail($serviceRequest->branch_id));

        $service->done($serviceRequest, $request->user());

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Menu with sold-out state for the waiter's sold-out toggle. */
    public function menu(Request $request, Branch $branch): JsonResponse
    {
        StaffAccess::authorize($request->user(), $branch);

        $rows = BranchMenuItem::query()
            ->where('branch_id', $branch->id)
            ->where('is_available', true)
            ->whereHas('menuItem', fn ($q) => $q->where('is_active', true))
            ->with('menuItem.category')
            ->get()
            ->sortBy(fn ($row) => [$row->menuItem->category?->sort_order, $row->menuItem->sort_order])
            ->map(fn (BranchMenuItem $row) => [
                'menu_item_id' => $row->menu_item_id,
                'name' => ['km' => $row->menuItem->name_km, 'en' => $row->menuItem->name_en],
                'category' => $row->menuItem->category?->name_en,
                'sold_out' => $row->isSoldOut(),
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function soldOut(Request $request, Branch $branch, int $menuItemId): JsonResponse
    {
        StaffAccess::authorize($request->user(), $branch);

        $data = $request->validate(['sold_out' => ['required', 'boolean']]);

        $row = BranchMenuItem::query()
            ->where('branch_id', $branch->id)
            ->where('menu_item_id', $menuItemId)
            ->firstOrFail();

        $data['sold_out'] ? $row->markSoldOut() : $row->markAvailable();

        return response()->json(['data' => ['menu_item_id' => $menuItemId, 'sold_out' => $row->fresh()->isSoldOut()]]);
    }
}
