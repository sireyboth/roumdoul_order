<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money received for a bill. Never deleted: a mistake is refunded with a reason. */
class Payment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'branch_id', 'bill_id', 'shift_id', 'idempotency_key', 'method', 'amount',
        'tendered_amount', 'tendered_currency', 'change_amount', 'change_currency', 'khr_per_usd',
        'reference', 'status', 'received_by_user_id', 'paid_at', 'business_date', 'refund_reason',
        'refunded_by_user_id', 'refunded_at', 'refunded_in_shift_id',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'integer',
            'tendered_amount' => 'integer',
            'change_amount' => 'integer',
            'khr_per_usd' => 'integer',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'business_date' => 'date',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
