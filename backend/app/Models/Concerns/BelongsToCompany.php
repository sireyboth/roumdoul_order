<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every restaurant-owned row carries company_id. Filament's tenancy scopes
 * back-office queries through the company() relation; public QR requests are
 * scoped by the table token instead (see MenuBuilder). New rows created inside
 * the back office are stamped with the current company automatically.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::creating(function ($model) {
            if (! $model->company_id && ($tenantId = Tenant::id())) {
                $model->company_id = $tenantId;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
