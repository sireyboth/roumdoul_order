<?php

namespace App\Support;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may work on which branch from the staff screens. A user needs an
 * active membership in the branch's company, and if they've been limited
 * to certain branches (branch_user rows), this branch must be one of them.
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

        $limitedTo = DB::table('branch_user')
            ->join('branches', 'branches.id', '=', 'branch_user.branch_id')
            ->where('branch_user.user_id', $user->id)
            ->where('branches.company_id', $branch->company_id)
            ->pluck('branch_user.branch_id');

        if ($limitedTo->isNotEmpty() && ! $limitedTo->contains($branch->id)) {
            return null;
        }

        return $membership;
    }

    /** @param  array<StaffRole>  $roles */
    public static function authorize(User $user, Branch $branch, array $roles = []): Membership
    {
        $membership = self::membership($user, $branch);

        abort_if(! $membership, 403, 'You do not work at this branch.');
        abort_if($roles !== [] && ! in_array($membership->role, $roles, true), 403, 'Your role cannot do this.');

        return $membership;
    }
}
