<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a restaurant owes Roumdoul for its plan: the first period after the trial, a renewal,
 * or the difference for an upgrade. Never deleted; see App\Services\Subscriptions\SubscriptionService.
 */
class PlanInvoice extends Model
{
    use Auditable, BelongsToCompany;

    public const KIND_FIRST = 'first';

    public const KIND_RENEWAL = 'renewal';

    public const KIND_UPGRADE = 'upgrade';

    public const METHODS = ['aba' => 'ABA transfer', 'khqr' => 'KHQR', 'cash' => 'Cash', 'other' => 'Other'];

    protected $fillable = [
        'company_id', 'subscription_id', 'kind', 'plan_id', 'interval', 'amount_cents', 'currency', 'description',
        'period_starts_at', 'period_ends_at', 'due_at', 'status', 'requested_by',
        'proof_path', 'proof_note', 'proof_sent_at', 'paid_at', 'paid_method', 'paid_reference', 'confirmed_by',
        'void_reason', 'voided_by', 'overdue_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'amount_cents' => 'integer',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'due_at' => 'datetime',
            'proof_sent_at' => 'datetime',
            'paid_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', InvoiceStatus::Pending->value);
    }

    /** e.g. INV-000012 */
    public function number(): string
    {
        return 'INV-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function amount(): string
    {
        return Money::format($this->amount_cents, $this->currency);
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::KIND_FIRST => 'First payment after the free trial',
            self::KIND_RENEWAL => 'Renewal',
            self::KIND_UPGRADE => 'Upgrade',
            default => ucfirst($this->kind),
        };
    }

    /** Unpaid renewals lock the back office (read-only) after the grace days; unpaid upgrades just don't upgrade. */
    public function locksWhenLate(): bool
    {
        return $this->kind !== self::KIND_UPGRADE;
    }

    public function graceEndsAt(): Carbon
    {
        return $this->due_at->copy()->addDays((int) config('tok.billing_grace_days'));
    }
}
