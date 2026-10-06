<?php

namespace App\Http\Controllers\Api;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Shift;
use App\Services\Billing\ShiftService;
use App\Support\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The cash drawer on the cashier screen: open a shift, cash in / out, count and close. */
class StaffShiftController extends Controller
{
    private const CASHIER = [StaffRole::Owner, StaffRole::Manager, StaffRole::Cashier];

    public function __construct(private ShiftService $shifts) {}

    /** The open shift with running totals, and the last closed one for reference. */
    public function current(Request $request, Branch $branch): JsonResponse
    {
        StaffAccess::authorize($request->user(), $branch, self::CASHIER);

        $open = ShiftService::openFor($branch->id);
        $last = Shift::query()->where('branch_id', $branch->id)->where('status', 'closed')->latest('closed_at')->first();

        return response()->json(['data' => [
            'shift' => $open ? self::present($open) : null,
            'last_closed' => $last ? self::present($last) : null,
        ]])->header('Cache-Control', 'no-store');
    }

    public function open(Request $request, Branch $branch): JsonResponse
    {
        StaffAccess::authorize($request->user(), $branch, self::CASHIER);

        $data = $request->validate([
            'opening_cash_usd' => ['required', 'integer', 'min:0', 'max:100000000'], // cents
            'opening_cash_khr' => ['required', 'integer', 'min:0', 'max:100000000000'], // riel
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $shift = $this->shifts->open($branch, $request->user(), $data['opening_cash_usd'], $data['opening_cash_khr'], $data['note'] ?? null);

        return response()->json(['data' => self::present($shift)], 201);
    }

    public function movement(Request $request, Shift $shift): JsonResponse
    {
        StaffAccess::authorize($request->user(), $shift->branch, self::CASHIER);

        $data = $request->validate([
            'type' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'currency' => ['required', Rule::in(['USD', 'KHR'])],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->shifts->addMovement($shift, $data['type'], $data['amount'], $data['currency'], $data['reason'], $request->user());

        return response()->json(['data' => self::present($shift->fresh())], 201);
    }

    public function close(Request $request, Shift $shift): JsonResponse
    {
        StaffAccess::authorize($request->user(), $shift->branch, self::CASHIER);

        $data = $request->validate([
            'counted_cash_usd' => ['required', 'integer', 'min:0', 'max:100000000'],
            'counted_cash_khr' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $shift = $this->shifts->close($shift, $data['counted_cash_usd'], $data['counted_cash_khr'], $data['note'] ?? null, $request->user());

        return response()->json(['data' => self::present($shift)]);
    }

    /** @return array<string, mixed> */
    public static function present(Shift $shift): array
    {
        $shift->loadMissing(['openedBy', 'closedBy', 'movements.user']);

        return [
            'id' => $shift->id,
            'status' => $shift->status,
            'opened_at' => $shift->opened_at?->toIso8601String(),
            'opened_by' => $shift->openedBy?->name,
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'closed_by' => $shift->closedBy?->name,
            'note' => $shift->note,
            'summary' => ShiftService::summary($shift),
            'counted_usd' => $shift->counted_cash_usd,
            'counted_khr' => $shift->counted_cash_khr,
            'difference_usd' => $shift->difference_usd,
            'difference_khr' => $shift->difference_khr,
            'movements' => $shift->movements->map(fn (CashMovement $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'amount' => $m->amount,
                'currency' => $m->currency,
                'reason' => $m->reason,
                'user' => $m->user?->name,
                'at' => $m->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
