<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\Company;

/**
 * Any change to the menu bumps a version number that is part of the cache key
 * (menu:{branch}:{companyVersion}.{branchVersion}), so customers can never be
 * served a stale menu. Uses a plain increment query: no model events, no loops.
 */
trait BumpsMenuVersion
{
    public static function bootBumpsMenuVersion(): void
    {
        $bump = fn ($model) => $model->bumpMenuVersion();

        static::saved($bump);
        static::deleted($bump);
    }

    public function bumpMenuVersion(): void
    {
        if (property_exists($this, 'bumpsBranchMenu') && $this->bumpsBranchMenu) {
            Branch::query()->whereKey($this->branch_id)->increment('menu_version');

            return;
        }

        if ($this->company_id) {
            Company::query()->whereKey($this->company_id)->increment('menu_version');
        }
    }
}
