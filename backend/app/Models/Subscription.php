<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Which plan a company is on, at what price, until when (`ends_at` = end of the paid period or
 * the trial). A plan change ends this row (`ended_at`) and starts a new one, so history is kept.
 * `Company::subscription()` = the latest row. Changes go through SubscriptionService.
 */
class Subscription extends Model
{
    use Auditable;

    public const INTERVALS = ['monthly' => 'Monthly', 'yearly' => 'Yearly'];

    protected $fillable = [
        'company_id', 'plan_id', 'status', 'interval', 'price_cents', 'currency',
        'scheduled_plan_id', 'scheduled_interval', 'starts_at', 'ends_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Downgrade that starts at the next renewal. */
    public function scheduledPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'scheduled_plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PlanInvoice::class);
    }

    public function isTrial(): bool
    {
        return $this->status === 'trialing';
    }

    /** Start of the current paid period (ends_at minus one interval). */
    public function periodStartsAt(): ?Carbon
    {
        return $this->ends_at ? self::addInterval($this->ends_at, $this->interval, -1) : null;
    }

    public function price(): string
    {
        return Money::format($this->price_cents, $this->currency).' / '.($this->interval === 'yearly' ? 'year' : 'month');
    }

    public static function addInterval(Carbon $date, string $interval, int $times = 1): Carbon
    {
        return $interval === 'yearly' ? $date->copy()->addYearsNoOverflow($times) : $date->copy()->addMonthsNoOverflow($times);
    }
}
