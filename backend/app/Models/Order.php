<?php

namespace App\Models;

use App\Enums\BillStatus;
use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Order extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'branch_id', 'dining_table_id', 'table_session_id', 'number', 'business_date', 'status',
        'source', 'placed_by_user_id', 'idempotency_key', 'note', 'currency', 'subtotal',
        'accepted_at', 'preparing_at', 'ready_at', 'served_at', 'completed_at', 'cancelled_at',
        'cancel_reason', 'cancelled_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'business_date' => 'date',
            'subtotal' => 'integer',
            'number' => 'integer',
            'accepted_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TableSession::class, 'table_session_id');
    }

    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by_user_id');
    }

    /**
     * The only way to change an order's status. Rejects moves the rules don't allow,
     * stamps the time, and writes the audit log (with a reason for cancellations).
     */
    public function moveTo(OrderStatus $next, ?User $by = null, ?string $reason = null): void
    {
        if ($this->status === $next) {
            return;
        }

        if (! $this->status->canMoveTo($next)) {
            throw ValidationException::withMessages([
                'status' => "Order #{$this->number} can't go from {$this->status->value} to {$next->value}.",
            ]);
        }

        if ($next === OrderStatus::Cancelled && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Please give a reason for cancelling.']);
        }

        // Once money has been taken for this visit, the bill is the record: refund first, then cancel.
        // (Open bill with a part payment, or a paid bill not refunded. A void bill holds no money.)
        $bill = $next === OrderStatus::Cancelled ? $this->session?->bill : null;
        $holdsMoney = $bill && ($bill->isOpen() ? $bill->paid_total > 0 : $bill->status === BillStatus::Paid && $bill->paid_total >= $bill->total);

        if ($holdsMoney) {
            throw ValidationException::withMessages([
                'status' => "Order #{$this->number} is on bill #{$bill->number}, which has payments. Refund the payment first, then cancel.",
            ]);
        }

        $before = $this->status->value;
        $changes = ['status' => $next];

        if ($column = $next->timestampColumn()) {
            $changes[$column] = now();
        }

        if ($next === OrderStatus::Cancelled) {
            $changes['cancel_reason'] = $reason;
            $changes['cancelled_by_user_id'] = $by?->id;
        }

        $this->update($changes);

        AuditLogger::record(
            'order.'.$next->value,
            $this,
            ['status' => $next->value],
            ['status' => $before],
            $reason,
            $this->company_id,
        );

        // Paid before the food came (common in cafés): once served, the order is finished.
        if ($next === OrderStatus::Served && $this->session?->status === 'closed') {
            $this->moveTo(OrderStatus::Completed, $by);
        }
    }
}
