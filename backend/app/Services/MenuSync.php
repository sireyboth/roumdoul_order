<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;

/**
 * Keeps one branch_menu_items row per branch x item, so the branch screens
 * always have a row to toggle. Uses insertOrIgnore: safe to run any time.
 */
class MenuSync
{
    public function ensureRowsForItem(MenuItem $item): void
    {
        $branchIds = Branch::query()->where('company_id', $item->company_id)->pluck('id');

        $this->insert($item->company_id, $branchIds->all(), [$item->id]);
    }

    public function ensureRowsForBranch(Branch $branch): void
    {
        $itemIds = MenuItem::query()->where('company_id', $branch->company_id)->pluck('id');

        $this->insert($branch->company_id, [$branch->id], $itemIds->all());
    }

    public function ensureRowsForCompany(int $companyId): void
    {
        $branchIds = Branch::query()->where('company_id', $companyId)->pluck('id')->all();
        $itemIds = MenuItem::query()->where('company_id', $companyId)->pluck('id')->all();

        $this->insert($companyId, $branchIds, $itemIds);
    }

    /**
     * @param  array<int>  $branchIds
     * @param  array<int>  $itemIds
     */
    private function insert(int $companyId, array $branchIds, array $itemIds): void
    {
        if ($branchIds === [] || $itemIds === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($branchIds as $branchId) {
            foreach ($itemIds as $itemId) {
                $rows[] = [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'menu_item_id' => $itemId,
                    'is_available' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('branch_menu_items')->insertOrIgnore($chunk);
        }

        Branch::query()->whereIn('id', $branchIds)->increment('menu_version');
    }
}
