<?php

namespace App\Support;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may see and work on which branch, on the staff screens and in the back office.
 *
 * - Owners: the whole company, every branch.
 * - Everyone else: only the branches ticked for them on the Staff page (branch_user rows).
 *   A restaurant with a single branch needs no ticks: everyone works there.
 */
final class StaffAccess
{
    public static function membership(User $user, Branch $branch): ?Membership
    {
        $membership = Membership::query()
            ->where('company_id', $branch->company_id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $membership || ! $user->is_active) {
            return null;
        }

        $allowed = self::branchIds($membership);

        return $allowed === null || in_array($branch->id, $allowed, true) ? $membership : null;
    }

    /** @param  array<StaffRole>  $roles */
    public static function authorize(User $user, Branch $branch, array $roles = []): Membership
    {
        $membership = self::membership($user, $branch);

        abort_if(! $membership, 403, 'You do not work at this branch.');
        abort_if($roles !== [] && ! in_array($membership->role, $roles, true), 403, 'Your role cannot do this.');

        return $membership;
    }

    /**
     * The branches a membership may see: null = every branch of the company (owners,
     * or a company with one branch); otherwise exactly the ticked branches (maybe none).
     *
     * @return array<int, int>|null
     */
    public static function branchIds(Membership $membership): ?array
    {
        if ($membership->role === StaffRole::Owner) {
            return null;
        }

        $branches = Branch::query()->where('company_id', $membership->company_id)->pluck('id');

        if ($branches->count() <= 1) {
            return null;
        }

        return DB::table('branch_user')
            ->where('user_id', $membership->user_id)
            ->whereIn('branch_id', $branches)
            ->pluck('branch_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** Same, for a user in a company (no membership = no branches). @return array<int, int>|null */
    public static function branchIdsFor(User $user, int $companyId): ?array
    {
        $membership = Membership::query()->where('company_id', $companyId)->where('user_id', $user->id)->first();

        return $membership ? self::branchIds($membership) : [];
    }
}
