<?php

namespace App\Http\Controllers\Api;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\FloorPlan;
use App\Models\TableArea;
use App\Services\AuditLogger;
use App\Support\Live;
use App\Support\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The drawn floor plan (where tables, bar, walls... are) for the staff screens.
 * Everyone at the branch can see it; only owners and managers can change it.
 */
class StaffFloorController extends Controller
{
    private const EDITORS = [StaffRole::Owner, StaffRole::Manager];

    public function show(Request $request, Branch $branch): JsonResponse
    {
        $membership = StaffAccess::authorize($request->user(), $branch);

        return $this->respond($branch, in_array($membership->role, self::EDITORS, true));
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        StaffAccess::authorize($request->user(), $branch, self::EDITORS);

        $data = $request->validate([
            'area_id' => ['nullable', 'integer', Rule::exists('table_areas', 'id')->where('branch_id', $branch->id)],
            'floor' => ['required', Rule::in(FloorPlan::FLOORS)],
            'width' => ['required', 'integer', 'between:400,4000'],
            'height' => ['required', 'integer', 'between:300,4000'],
            'objects' => ['present', 'array', 'max:400'],
            'objects.*' => ['array'],
            'objects.*.id' => ['required', 'string', 'between:1,40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'objects.*.kind' => ['required', Rule::in(FloorPlan::KINDS)],
            'objects.*.x' => ['required', 'integer', 'between:-200,4200'],
            'objects.*.y' => ['required', 'integer', 'between:-200,4200'],
            'objects.*.w' => ['required', 'integer', 'between:10,2000'],
            'objects.*.h' => ['required', 'integer', 'between:10,2000'],
            'objects.*.rotation' => ['required', 'integer', 'between:0,359'],
            'objects.*.table_id' => ['nullable', 'integer', 'required_if:objects.*.kind,'.implode(',', FloorPlan::TABLE_KINDS)],
            'objects.*.seats' => ['nullable', 'integer', 'between:0,40'],
            'objects.*.label' => ['nullable', 'string', 'max:40'],
            'objects.*.color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $activeTables = DiningTable::query()
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->pluck('id')
            ->flip();

        $objects = [];
        $seats = [];
        $errors = [];

        foreach (array_values($data['objects']) as $i => $object) {
            $isTable = in_array($object['kind'], FloorPlan::TABLE_KINDS, true);
            // table_id only means something on a table; it is ignored on a plant or a wall.
            $tableId = $isTable ? (int) $object['table_id'] : null;
            $seatCount = $isTable && isset($object['seats']) ? (int) $object['seats'] : null;

            if ($isTable) {
                if (! $activeTables->has($tableId)) {
                    $errors["objects.$i.table_id"] = 'This table is not an active table of this branch.';
                } elseif (array_key_exists($tableId, $seats)) {
                    $errors["objects.$i.table_id"] = 'This table is already on the plan.';
                }

                $seats[$tableId] = $seatCount;
            }

            // Only known keys are stored.
            $objects[] = [
                'id' => $object['id'],
                'kind' => $object['kind'],
                'x' => (int) $object['x'],
                'y' => (int) $object['y'],
                'w' => (int) $object['w'],
                'h' => (int) $object['h'],
                'rotation' => (int) $object['rotation'],
                'table_id' => $tableId,
                'seats' => $seatCount,
                'label' => ($object['label'] ?? '') !== '' ? $object['label'] : null,
                'color' => $object['color'] ?? null,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $areaId = isset($data['area_id']) ? (int) $data['area_id'] : null;

        DB::transaction(function () use ($branch, $areaId, $data, $objects, $seats) {
            $plan = FloorPlan::forFloor($branch, $areaId);
            $plan->fill([
                'floor' => $data['floor'],
                'width' => (int) $data['width'],
                'height' => (int) $data['height'],
                'objects' => $objects,
            ])->save();

            // A table stands on one floor only: take the posted tables off every other plan.
            $placed = array_keys($seats);

            if ($placed !== []) {
                FloorPlan::query()
                    ->where('branch_id', $branch->id)
                    ->whereKeyNot($plan->id)
                    ->get()
                    ->each(function (FloorPlan $other) use ($placed) {
                        $all = $other->objects ?? [];
                        $kept = array_values(array_filter(
                            $all,
                            fn (array $o) => ! in_array($o['table_id'] ?? null, $placed, true),
                        ));

                        if (count($kept) !== count($all)) {
                            $other->update(['objects' => $kept]);
                        }
                    });
            }

            // Seats drawn on the plan become the table's seat count (0 = not set).
            foreach ($seats as $tableId => $count) {
                if ($count === null) {
                    continue;
                }

                DiningTable::query()
                    ->where('branch_id', $branch->id)
                    ->find($tableId)
                    ?->update(['seats' => $count > 0 ? $count : null]);
            }

            AuditLogger::record('floor_plan.saved', $plan, [
                'area_id' => $areaId,
                'floor' => $plan->floor,
                'objects' => count($objects),
                'tables' => $placed,
            ]);
        });

        // Cashier and waiter screens re-fetch (table seats may have changed).
        Live::changed($branch->id);

        return $this->respond($branch, true);
    }

    private function respond(Branch $branch, bool $canEdit): JsonResponse
    {
        $areas = TableArea::query()
            ->where('branch_id', $branch->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'sort_order']);

        $areaOrder = $areas->pluck('sort_order', 'id');

        // Same order as the cashier screen: area, then table order, then name.
        $tables = DiningTable::query()
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->get(['id', 'name', 'seats', 'table_area_id', 'sort_order'])
            ->sortBy(fn (DiningTable $t) => [$areaOrder[$t->table_area_id] ?? 999, $t->sort_order, $t->name])
            ->map(fn (DiningTable $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'seats' => $t->seats !== null ? (int) $t->seats : null,
                'area_id' => $t->table_area_id,
            ])
            ->values();

        // One plan per floor. If an area was deleted its plan fell back to "Main floor": newest wins.
        $floors = FloorPlan::query()
            ->where('branch_id', $branch->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (FloorPlan $p) => $p->table_area_id ?? 0)
            ->sortBy(fn (FloorPlan $p) => $p->table_area_id ? ($areaOrder[$p->table_area_id] ?? 999) : -1)
            ->map(fn (FloorPlan $p) => [
                'area_id' => $p->table_area_id,
                'floor' => $p->floor,
                'width' => $p->width,
                'height' => $p->height,
                'objects' => $p->objects ?? [],
                'updated_at' => $p->updated_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['data' => [
            'can_edit' => $canEdit,
            'areas' => $areas->map(fn (TableArea $a) => ['id' => $a->id, 'name' => $a->name])->values(),
            'tables' => $tables,
            'floors' => $floors,
        ]])->header('Cache-Control', 'no-store');
    }
}
