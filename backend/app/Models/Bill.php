<?php

namespace App\Models;

use App\Enums\BillStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Live;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a table visit owes. Amounts are recomputed by BillService while the
 * bill is open and frozen once it is paid or void. Change it only through
 * App\Services\Billing\BillService.
 */
class Bill extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'branch_id', 'table_session_id', 'number', 'business_date', 'currency', 'khr_per_usd',
        'service_charge_bp', 'vat_bp', 'prices_include_vat', 'subtotal', 'discount_total', 'service_charge',
        'vat', 'total', 'total_khr', 'paid_total', 'status', 'opened_by_user_id', 'paid_at', 'voided_at',
        'void_reason', 'voided_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => BillStatus::class,
            'business_date' => 'date',
            'prices_include_vat' => 'boolean',
            'number' => 'integer',
            'khr_per_usd' => 'integer',
            'service_charge_bp' => 'integer',
            'vat_bp' => 'integer',
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'service_charge' => 'integer',
            'vat' => 'integer',
            'total' => 'integer',
            'total_khr' => 'integer',
            'paid_total' => 'integer',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Staff screens and the customer phone update at once (Reverb); see App\Support\Live.
        static::saved(function (self $bill) {
            if (Live::enabled()) {
                Live::changed($bill->branch_id, TableSession::query()->whereKey($bill->table_session_id)->value('dining_table_id'));
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TableSession::class, 'table_session_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(BillAdjustment::class);
    }

    /** Discounts that still count (removed ones stay for the audit trail). */
    public function activeAdjustments(): HasMany
    {
        return $this->adjustments()->whereNull('removed_at')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === BillStatus::Open;
    }

    /** Still to pay, in the bill currency. */
    public function remaining(): int
    {
        return max(0, $this->total - $this->paid_total);
    }
}
