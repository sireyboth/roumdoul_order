<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\BumpsMenuVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How one branch differs from the master menu: hidden, different price, or sold out. */
class BranchMenuItem extends Model
{
    use Auditable, BelongsToCompany, BumpsMenuVersion;

    /** Changes here only affect one branch's menu cache. */
    protected bool $bumpsBranchMenu = true;

    protected $fillable = ['company_id', 'branch_id', 'menu_item_id', 'is_available', 'price', 'sold_out_until'];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'price' => 'integer',
            'sold_out_until' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function isSoldOut(): bool
    {
        return $this->sold_out_until !== null && $this->sold_out_until->isFuture();
    }

    /** Sold out until the end of today's business day (Step 1 staff screens use this). */
    public function markSoldOut(?\DateTimeInterface $until = null): void
    {
        $this->update(['sold_out_until' => $until ?? now()->endOfDay()]);
    }

    public function markAvailable(): void
    {
        $this->update(['sold_out_until' => null]);
    }
}
