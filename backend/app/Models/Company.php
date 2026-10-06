<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Models\Concerns\Auditable;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Company extends Model implements HasAvatar, HasName
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'email', 'phone', 'logo_path', 'timezone', 'currency', 'khr_per_usd',
        'vat_bp', 'service_charge_bp', 'prices_include_vat', 'status', 'trial_ends_at',
        'telegram_chat_id', 'coreos_company_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'trial_ends_at' => 'datetime',
            'prices_include_vat' => 'boolean',
        ];
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->withPivot(['id', 'role', 'is_active'])
            ->withTimestamps();
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function tableAreas(): HasMany
    {
        return $this->hasMany(TableArea::class);
    }

    public function diningTables(): HasMany
    {
        return $this->hasMany(DiningTable::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(OptionGroup::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(Option::class);
    }

    public function branchMenuItems(): HasMany
    {
        return $this->hasMany(BranchMenuItem::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /** Can customers order right now? Trials count until their end date. */
    public function isOperational(): bool
    {
        return match ($this->status) {
            CompanyStatus::Active => true,
            CompanyStatus::Trial => $this->trial_ends_at === null || $this->trial_ends_at->isFuture(),
            default => false,
        };
    }

    /**
     * Plan limits (null = unlimited). Checked before creating branches, tables
     * or staff so going over the plan is a clear message, not a billing dispute.
     */
    public function limitFor(string $resource): ?int
    {
        $plan = $this->subscription?->plan;

        return match ($resource) {
            'branches' => $plan?->max_branches,
            'tables' => $plan?->max_tables,
            'staff' => $plan?->max_staff,
            default => null,
        };
    }

    public function hasReachedLimit(string $resource): bool
    {
        $limit = $this->limitFor($resource);

        if ($limit === null) {
            return false;
        }

        $count = match ($resource) {
            'branches' => $this->branches()->count(),
            'tables' => $this->diningTables()->count(),
            'staff' => $this->memberships()->count(),
            default => 0,
        };

        return $count >= $limit;
    }
}
