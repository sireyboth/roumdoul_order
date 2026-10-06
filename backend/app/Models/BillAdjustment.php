<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A discount on a bill: percent (value in basis points) or fixed (minor units). */
class BillAdjustment extends Model
{
    use BelongsToCompany;

    public const TYPES = ['percent', 'fixed'];

    protected $fillable = [
        'company_id', 'bill_id', 'type', 'value', 'amount', 'reason', 'created_by_user_id',
        'approved_by_user_id', 'removed_at', 'removed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'amount' => 'integer',
            'removed_at' => 'datetime',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
