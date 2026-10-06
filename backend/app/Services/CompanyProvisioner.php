<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a restaurant account in one transaction: company, trial
 * subscription, the signing-up user as owner, and its first branch.
 * Used by self sign-up in /app and by the platform team in /admin.
 */
class CompanyProvisioner
{
    public const TRIAL_DAYS = 14;

    /** Words used by /app routes; a company slug must never equal one of them. */
    private const RESERVED_SLUGS = ['profile', 'login', 'logout', 'register', 'new', 'password-reset', 'email-verification', 'tenant'];

    /**
     * @param  array{name: string, slug?: string|null, phone?: string|null, currency?: string|null, branch_name?: string|null}  $data
     */
    public function create(array $data, User $owner, ?string $planCode = null): Company
    {
        return DB::transaction(function () use ($data, $owner, $planCode) {
            $company = Company::query()->create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['slug'] ?? $data['name']),
                'phone' => $data['phone'] ?? null,
                'email' => $owner->email,
                'currency' => $data['currency'] ?? 'USD',
                'status' => CompanyStatus::Trial,
                'trial_ends_at' => now()->addDays(self::TRIAL_DAYS),
            ]);

            $plan = Plan::query()->where('code', $planCode ?? 'starter')->first()
                ?? Plan::query()->where('is_active', true)->orderBy('sort_order')->first();

            if ($plan) {
                Subscription::query()->create([
                    'company_id' => $company->id,
                    'plan_id' => $plan->id,
                    'status' => 'trialing',
                    'interval' => 'monthly',
                    'starts_at' => now(),
                    'ends_at' => $company->trial_ends_at,
                ]);
            }

            $company->memberships()->create([
                'user_id' => $owner->id,
                'role' => StaffRole::Owner,
                'is_active' => true,
            ]);

            Branch::query()->create([
                'company_id' => $company->id,
                'name' => $data['branch_name'] ?? 'Main branch',
                'code' => 'MAIN',
            ]);

            return $company;
        });
    }

    public function uniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'restaurant';
        $base = Str::limit($base, 60, '');
        $slug = $base;
        $i = 2;

        while (in_array($slug, self::RESERVED_SLUGS, true) || Company::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
