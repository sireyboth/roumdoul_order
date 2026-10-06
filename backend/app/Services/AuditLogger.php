<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * One place to record who changed what. Money-related actions in Step 1
 * (voids, refunds, discounts, price edits) must always pass a $reason.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $before
     */
    public static function record(
        string $event,
        ?Model $subject = null,
        array $after = [],
        array $before = [],
        ?string $reason = null,
        ?int $companyId = null,
    ): AuditLog {
        $hidden = ['password', 'remember_token', 'pin_hash'];

        // A company's own changes belong to that company's log.
        $companyId ??= $subject instanceof Company ? $subject->getKey() : null;

        return AuditLog::query()->create([
            'company_id' => $companyId ?? $subject?->getAttribute('company_id') ?? Tenant::id(),
            'user_id' => Auth::id(),
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'reason' => $reason,
            'before_data' => collect($before)->except($hidden)->all() ?: null,
            'after_data' => collect($after)->except($hidden)->all() ?: null,
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 255) ?: null,
        ]);
    }
}
