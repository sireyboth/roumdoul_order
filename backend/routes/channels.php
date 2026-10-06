<?php

use App\Models\Branch;
use App\Models\User;
use App\Support\StaffAccess;
use Illuminate\Support\Facades\Broadcast;

// Staff screens of one branch. Same rule as the staff API: an active job at that branch.
Broadcast::channel('branch.{branchId}', function (User $user, int $branchId) {
    $branch = Branch::query()->find($branchId);

    return $branch !== null && StaffAccess::membership($user, $branch) !== null;
});
