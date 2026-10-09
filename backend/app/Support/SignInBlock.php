<?php

namespace App\Support;

use App\Enums\CompanyStatus;
use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;

/**
 * Explains why a person with the RIGHT password still cannot sign in, so they
 * know who can fix it. Only used after the password is checked: a wrong password
 * always gets the plain "wrong email or password" answer.
 *
 * Who can unblock what:
 *  - whole account (users.is_active): Roumdoul, in /admin > Users
 *  - one restaurant (memberships.is_active, branches): the owner / manager, on the Staff page
 *  - restaurant suspended, cancelled or trial ended: Roumdoul, in /admin > Restaurants
 */
class SignInBlock
{
    public const SUPPORT = 'Contact Roumdoul: +855 15 57 87 07.';

    /** /admin: platform team only. */
    public static function forAdmin(User $user): ?string
    {
        if (! $user->is_active) {
            return self::accountBlocked();
        }

        return $user->is_platform_admin
            ? null
            : 'This account cannot open the Roumdoul admin. Restaurant owners and managers sign in at /app.';
    }

    /** /app back office: owners and managers. People with no restaurant yet may sign in to register one. */
    public static function forBackOffice(User $user): ?string
    {
        if (! $user->is_active) {
            return self::accountBlocked();
        }

        if ($user->backOfficeCompanies()->exists()) {
            return null;
        }

        $memberships = Membership::query()->where('user_id', $user->id)->with('company')->get()
            ->filter(fn (Membership $m) => $m->company !== null);

        if ($memberships->isEmpty()) {
            return null;
        }

        $leads = $memberships->filter(fn (Membership $m) => in_array($m->role, [StaffRole::Owner, StaffRole::Manager], true));

        if ($closed = $leads->first(fn (Membership $m) => $m->is_active && $m->company->status === CompanyStatus::Cancelled)) {
            return "{$closed->company->name} is closed. ".self::SUPPORT;
        }

        if ($off = $leads->first(fn (Membership $m) => ! $m->is_active)) {
            return "Your access to {$off->company->name} is switched off. Ask the restaurant owner to turn it back on (Staff page).";
        }

        return 'This account is for the staff screens (kitchen, waiter, cashier). Sign in there instead.';
    }

    /** Staff screens: called when the password is right but no branch is open to this person. */
    public static function forStaff(User $user): string
    {
        if (! $user->is_active) {
            return self::accountBlocked();
        }

        $memberships = Membership::query()->where('user_id', $user->id)->with('company')->get()
            ->filter(fn (Membership $m) => $m->company !== null);

        if ($memberships->isEmpty()) {
            return 'This account is not on the staff of any restaurant.';
        }

        $active = $memberships->where('is_active', true);

        if ($active->isEmpty()) {
            $name = $memberships->first()->company->name;

            return "Your account at {$name} is switched off. Ask the owner or a manager to turn it back on (Staff page).";
        }

        $paused = $active->first(fn (Membership $m) => ! $m->company->isOperational());

        if ($paused && $active->every(fn (Membership $m) => ! $m->company->isOperational())) {
            return self::companyPaused($paused->company);
        }

        $hasOpenBranch = Branch::query()->whereIn('company_id', $active->pluck('company_id'))->where('is_active', true)->exists();

        return $hasOpenBranch
            ? 'You are not given any branch yet. Ask the owner or a manager to tick your branch on the Staff page.'
            : 'Your restaurant has no open branch. Ask the owner to turn a branch back on.';
    }

    private static function accountBlocked(): string
    {
        return 'This account is blocked. '.self::SUPPORT;
    }

    private static function companyPaused(Company $company): string
    {
        return match (true) {
            $company->status === CompanyStatus::Trial => "{$company->name}'s free trial has ended. The owner can ".lcfirst(self::SUPPORT),
            $company->status === CompanyStatus::Cancelled => "{$company->name} is closed. The owner can ".lcfirst(self::SUPPORT),
            default => "{$company->name} is paused. The owner can ".lcfirst(self::SUPPORT),
        };
    }
}
