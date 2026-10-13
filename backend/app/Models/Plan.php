<?php

namespace App\Models;

use App\Enums\Feature;
use App\Models\Concerns\Auditable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use Auditable;

    protected $fillable = [
        'code', 'name', 'description', 'max_branches', 'max_tables', 'max_staff',
        'price_monthly_cents', 'price_yearly_cents', 'price_per_order_cents', 'currency', 'features', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function hasFeature(Feature|string $key): bool
    {
        return in_array($key instanceof Feature ? $key->value : $key, $this->features ?? [], true);
    }

    /** Pay-per-order plan: billed for the orders of the period that ended, not in advance. */
    public function isPerOrder(): bool
    {
        return $this->price_per_order_cents !== null;
    }

    /** Price for one interval, in cents. */
    public function priceFor(string $interval): int
    {
        return (int) ($interval === 'yearly' ? $this->price_yearly_cents : $this->price_monthly_cents);
    }

    public function priceLabel(string $interval): string
    {
        if ($this->isPerOrder()) {
            return Money::format($this->price_per_order_cents, $this->currency).' / order';
        }

        return Money::format($this->priceFor($interval), $this->currency).' / '.($interval === 'yearly' ? 'year' : 'month');
    }
}
