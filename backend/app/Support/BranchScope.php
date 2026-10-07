<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Back office: limits branch data (orders, shifts, sales, tables, branches) to the
 * branches the signed-in person may see. Owners see every branch of the company;
 * a manager sees only their ticked branches. See StaffAccess::branchIds().
 */
final class BranchScope
{
    /** @return array<int, int>|null null = every branch of the current company */
    public static function ids(): ?array
    {
        $user = Auth::user();
        $companyId = Tenant::id();

        if (! $user instanceof User || ! $companyId) {
            return [];
        }

        return StaffAccess::branchIdsFor($user, $companyId);
    }

    public static function seesAllBranches(): bool
    {
        return self::ids() === null;
    }

    /** Adds "branch is one of mine" to a query; does nothing for owners. */
    public static function apply(Builder $query, string $column = 'branch_id'): Builder
    {
        $ids = self::ids();

        return $ids === null ? $query : $query->whereIn($query->getModel()->qualifyColumn($column), $ids);
    }

    /** For branch pickers and filters: this company's branches that I may see. */
    public static function branches(Builder $query): Builder
    {
        return self::apply(TenantScope::apply($query), 'id');
    }
}
