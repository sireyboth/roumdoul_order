<?php

namespace App\Services\Ordering;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\BranchMenuItem;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\TableSession;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a cart into an order. The phone only says WHAT it wants (item ids,
 * option ids, quantities); every price is looked up here, so a tampered
 * request can never change what the restaurant charges.
 */
class OrderPlacer
{
    public const MAX_LINES = 40;

    public const MAX_QUANTITY = 50;

    /**
     * @param  array<int, array{menu_item_id:int, quantity:int, option_ids?:array<int>, note?:string|null}>  $lines
     */
    public function place(
        DiningTable $table,
        array $lines,
        ?string $note = null,
        ?string $idempotencyKey = null,
        ?User $placedBy = null,
    ): Order {
        // A retry of the same tap (bad signal, double tap) returns the order already made.
        if ($idempotencyKey && ($existing = $this->existing($table, $idempotencyKey))) {
            return $existing;
        }

        $branch = Branch::query()->with('company')->findOrFail($table->branch_id);
        $company = $branch->company;

        $priced = $this->priceLines($company, $branch, $lines);

        try {
            return DB::transaction(function () use ($table, $branch, $company, $priced, $note, $idempotencyKey, $placedBy) {
                // Locks serialise orders per branch: no duplicate numbers, one open session per table.
                Branch::query()->whereKey($branch->id)->lockForUpdate()->first();
                DiningTable::query()->whereKey($table->id)->lockForUpdate()->first();

                $session = TableSession::query()
                    ->where('dining_table_id', $table->id)
                    ->where('status', '!=', 'closed')
                    ->latest('id')
                    ->first()
                    ?? TableSession::query()->create([
                        'company_id' => $company->id,
                        'branch_id' => $branch->id,
                        'dining_table_id' => $table->id,
                        'status' => 'open',
                        'opened_at' => now(),
                    ]);

                $businessDate = $branch->businessDate();
                $number = (int) Order::query()
                    ->where('branch_id', $branch->id)
                    ->whereDate('business_date', $businessDate)
                    ->max('number') + 1;

                $order = Order::query()->create([
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'dining_table_id' => $table->id,
                    'table_session_id' => $session->id,
                    'number' => $number,
                    'business_date' => $businessDate,
                    'status' => OrderStatus::Placed,
                    'source' => $placedBy ? 'waiter' : 'qr',
                    'placed_by_user_id' => $placedBy?->id,
                    'idempotency_key' => $idempotencyKey,
                    'note' => $note ? mb_substr(trim($note), 0, 255) : null,
                    'currency' => $company->currency,
                    'subtotal' => $priced->sum('line_total'),
                ]);

                foreach ($priced as $line) {
                    $order->items()->create($line + ['company_id' => $company->id]);
                }

                AuditLogger::record('order.placed', $order, [
                    'number' => $number,
                    'subtotal' => $order->subtotal,
                    'source' => $order->source,
                ], companyId: $company->id);

                return $order->load('items');
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Two identical taps raced past the first check; the other one won.
            if ($idempotencyKey && ($existing = $this->existing($table, $idempotencyKey))) {
                return $existing;
            }

            throw $e;
        }
    }

    private function existing(DiningTable $table, string $key): ?Order
    {
        return Order::query()
            ->where('dining_table_id', $table->id)
            ->where('idempotency_key', $key)
            ->with('items')
            ->first();
    }

    /**
     * Validates every line against the live menu and returns rows ready for order_items.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function priceLines(Company $company, Branch $branch, array $lines): Collection
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Your order is empty.']);
        }

        if (count($lines) > self::MAX_LINES) {
            throw ValidationException::withMessages(['items' => 'Too many lines in one order.']);
        }

        $itemIds = collect($lines)->pluck('menu_item_id')->map(fn ($id) => (int) $id)->unique();

        $items = MenuItem::query()
            ->where('company_id', $company->id)
            ->whereIn('id', $itemIds)
            ->with(['optionGroups.options'])
            ->get()
            ->keyBy('id');

        $settings = BranchMenuItem::query()
            ->where('branch_id', $branch->id)
            ->whereIn('menu_item_id', $itemIds)
            ->get()
            ->keyBy('menu_item_id');

        return collect($lines)->values()->map(function (array $line, int $index) use ($items, $settings) {
            $key = "items.$index";
            $item = $items->get((int) ($line['menu_item_id'] ?? 0));
            $setting = $settings->get((int) ($line['menu_item_id'] ?? 0));

            if (! $item || ! $item->is_active || ($setting && ! $setting->is_available)) {
                throw ValidationException::withMessages([$key => 'This item is not on the menu any more. Please refresh the menu.']);
            }

            if ($setting?->isSoldOut()) {
                throw ValidationException::withMessages([$key => "{$item->name_en} is sold out."]);
            }

            $quantity = (int) ($line['quantity'] ?? 0);
            if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
                throw ValidationException::withMessages([$key => 'Please choose a quantity between 1 and '.self::MAX_QUANTITY.'.']);
            }

            $chosenIds = collect($line['option_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();
            $chosen = collect();

            foreach ($item->optionGroups as $group) {
                $available = $group->options->where('is_active', true)->keyBy('id');
                $picked = $chosenIds->filter(fn ($id) => $available->has($id));

                if ($picked->count() < $group->min_select || $picked->count() > $group->max_select) {
                    throw ValidationException::withMessages([
                        $key => $group->min_select === $group->max_select
                            ? "Please choose {$group->min_select} for {$group->name_en}."
                            : "Please choose {$group->min_select} to {$group->max_select} for {$group->name_en}.",
                    ]);
                }

                foreach ($picked as $id) {
                    $option = $available->get($id);
                    $chosen->push([
                        'id' => $option->id,
                        'group_en' => $group->name_en,
                        'group_km' => $group->name_km,
                        'name_en' => $option->name_en,
                        'name_km' => $option->name_km,
                        'price_delta' => $option->price_delta,
                    ]);
                }
            }

            // Any option id not belonging to this item's groups is a stale or tampered request.
            if ($chosen->count() !== $chosenIds->count()) {
                throw ValidationException::withMessages([$key => 'Some choices are no longer available. Please choose again.']);
            }

            $unitPrice = ($setting?->price ?? $item->price) + (int) $chosen->sum('price_delta');

            return [
                'menu_item_id' => $item->id,
                'name_km' => $item->name_km,
                'name_en' => $item->name_en,
                'station' => $item->station->value,
                'unit_price' => max(0, $unitPrice),
                'quantity' => $quantity,
                'line_total' => max(0, $unitPrice) * $quantity,
                'options' => $chosen->values()->all() ?: null,
                'note' => filled($line['note'] ?? null) ? mb_substr(trim($line['note']), 0, 120) : null,
            ];
        });
    }
}
