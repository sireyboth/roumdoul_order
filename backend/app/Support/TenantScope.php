<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * For relationship Select fields in the back office: only show records of the
 * current company. Filament scopes resource tables itself, but option lists
 * built from a relationship must be limited explicitly.
 */
final class TenantScope
{
    public static function apply(Builder $query): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('company_id'), Tenant::id());
    }
}
