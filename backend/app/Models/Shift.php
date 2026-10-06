<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Live;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One cash drawer session at a branch: counted in at the start, counted out at
 * the end. Change it only through App\Services\Billing\ShiftService.
 */
class Shift extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'branch_id', 'status', 'opened_by_user_id', 'opened_at', 'opening_cash_usd', 'opening_cash_khr',
        'closed_by_user_id', 'closed_at', 'expected_cash_usd', 'expected_cash_khr', 'counted_cash_usd',
        'counted_cash_khr', 'difference_usd', 'difference_khr', 'note',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash_usd' => 'integer',
            'opening_cash_khr' => 'integer',
            'expected_cash_usd' => 'integer',
            'expected_cash_khr' => 'integer',
            'counted_cash_usd' => 'integer',
            'counted_cash_khr' => 'integer',
            'difference_usd' => 'integer',
            'difference_khr' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Staff screens and the customer phone update at once (Reverb); see App\Support\Live.
        static::saved(fn (self $shift) => Live::changed($shift->branch_id));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
