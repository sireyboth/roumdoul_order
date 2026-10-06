<?php

namespace App\Models;

use App\Enums\StaffRole;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'is_platform_admin',
        'is_active',
        'locale',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->using(Membership::class)
            ->withPivot(['id', 'role', 'is_active'])
            ->withTimestamps();
    }

    /** Companies whose back office this user may open: active owner/manager memberships only. */
    public function backOfficeCompanies(): BelongsToMany
    {
        return $this->companies()
            ->wherePivot('is_active', true)
            ->wherePivotIn('role', [StaffRole::Owner->value, StaffRole::Manager->value])
            ->whereNotIn('companies.status', ['cancelled']);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->is_platform_admin,
            // Anyone may sign in to /app; with no company yet they are sent to "Register your restaurant".
            'app' => true,
            default => false,
        };
    }

    public function getTenants(Panel $panel): array|Collection
    {
        return $this->backOfficeCompanies()->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->backOfficeCompanies()->whereKey($tenant->getKey())->exists();
    }

    public function roleIn(Company $company): ?StaffRole
    {
        $role = $this->memberships()->where('company_id', $company->getKey())->value('role');

        if ($role instanceof StaffRole || $role === null) {
            return $role;
        }

        return StaffRole::from($role);
    }
}
