<?php

namespace App\Services;

use App\Models\BranchMenuItem;
use App\Models\Category;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the menu a customer sees after scanning a table QR.
 *
 * Hot path: one indexed query (token -> table + branch + company + versions),
 * then the ready-made branch menu from cache. The database is only read in
 * full when the menu changed (its version number moved) or the cache expired.
 *
 * Security: the QR token is the only input. Company and branch always come
 * from the token's table row, never from anything else in the request.
 */
class MenuBuilder
{
    public static function tokenCacheKey(string $token): string
    {
        return 'qr:'.hash('sha256', $token);
    }

    public static function forgetToken(?string $token): void
    {
        if ($token) {
            Cache::forget(self::tokenCacheKey($token));
        }
    }

    /**
     * The table behind a QR token, but only if it can take orders right now:
     * table active, branch open, restaurant active (or in trial). Null otherwise.
     */
    public function resolveTable(string $token): ?DiningTable
    {
        $ids = Cache::remember(self::tokenCacheKey($token), 600, function () use ($token) {
            $table = DiningTable::query()
                ->where('qr_token', $token)
                ->first(['id', 'company_id', 'branch_id', 'table_area_id', 'name', 'is_active']);

            return $table?->only(['id', 'company_id', 'branch_id', 'table_area_id', 'name', 'is_active']);
        });

        if (! $ids || ! $ids['is_active']) {
            return null;
        }

        // Fresh every request: status and versions must never be stale.
        $company = Company::query()->find($ids['company_id']);
        $branch = $company?->branches()->whereKey($ids['branch_id'])->first();

        if (! $company || ! $branch || ! $branch->is_active || ! $company->isOperational()) {
            return null;
        }

        $table = (new DiningTable)->forceFill($ids);
        $table->exists = true;
        $branch->setRelation('company', $company);
        $table->setRelation('branch', $branch);

        return $table;
    }

    /**
     * @return array<string, mixed>|null null when the token is unknown, the table/branch is off,
     *                                   or the restaurant can't take orders (suspended, trial ended).
     */
    public function forToken(string $token): ?array
    {
        $table = $this->resolveTable($token);

        if (! $table) {
            return null;
        }

        $branch = $table->branch;
        $company = $branch->company;
        $ids = $table->only(['name', 'table_area_id']);

        $menuKey = sprintf('menu:%d:%d.%d', $branch->id, $company->menu_version, $branch->menu_version);

        $menu = Cache::remember($menuKey, config('tok.menu_cache_ttl'), fn () => $this->buildMenu($company, $branch->id));

        $areaName = $ids['table_area_id']
            ? $branch->tableAreas()->whereKey($ids['table_area_id'])->value('name')
            : null;

        return [
            'company' => [
                'name' => $company->name,
                'logo_url' => $company->logo_path ? Storage::disk('public')->url($company->logo_path) : null,
                'currency' => $company->currency,
                'khr_per_usd' => $company->khr_per_usd,
                'vat_bp' => $company->vat_bp,
                'service_charge_bp' => $company->service_charge_bp,
                'prices_include_vat' => $company->prices_include_vat,
            ],
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'address' => $branch->address,
                'phone' => $branch->phone,
            ],
            'table' => [
                'name' => $ids['name'],
                'area' => $areaName,
            ],
            'menu_version' => $company->menu_version.'.'.$branch->menu_version,
            'categories' => $menu,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function buildMenu(Company $company, int $branchId): array
    {
        $settings = BranchMenuItem::query()
            ->where('branch_id', $branchId)
            ->get(['menu_item_id', 'is_available', 'price', 'sold_out_until'])
            ->keyBy('menu_item_id');

        $items = MenuItem::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->with(['optionGroups.options' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get()
            // Hidden in this branch = not on its menu at all.
            ->filter(fn (MenuItem $item) => $settings->get($item->id)?->is_available ?? true)
            ->groupBy('category_id');

        return Category::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $this->names($category),
                'image_url' => $this->imageUrl($category->image_path),
                'items' => ($items->get($category->id) ?? collect())
                    ->map(fn (MenuItem $item) => $this->item($item, $settings->get($item->id)))
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $category) => $category['items'] !== [])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function item(MenuItem $item, ?BranchMenuItem $setting): array
    {
        return [
            'id' => $item->id,
            'name' => $this->names($item),
            'description' => [
                'km' => $item->description_km,
                'en' => $item->description_en,
            ],
            'image_url' => $this->imageUrl($item->image_path),
            // Branch price wins over the company price.
            'price' => $setting?->price ?? $item->price,
            // ISO time or null. The phone compares it to "now", so a cached menu
            // still shows the item again as soon as the sold-out time passes.
            'sold_out_until' => $setting?->sold_out_until?->toIso8601String(),
            'option_groups' => $item->optionGroups
                ->filter(fn ($group) => $group->options->isNotEmpty())
                ->map(fn ($group) => [
                    'id' => $group->id,
                    'name' => ['km' => $group->name_km, 'en' => $group->name_en],
                    'min' => $group->min_select,
                    'max' => $group->max_select,
                    'options' => $group->options->map(fn ($option) => [
                        'id' => $option->id,
                        'name' => ['km' => $option->name_km, 'en' => $option->name_en],
                        'price_delta' => $option->price_delta,
                        'is_default' => $option->is_default,
                    ])->values()->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, string|null> */
    private function names(Category|MenuItem $model): array
    {
        return [
            'km' => $model->name_km,
            'en' => $model->name_en,
            'zh' => $model->name_zh,
        ];
    }

    private function imageUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
