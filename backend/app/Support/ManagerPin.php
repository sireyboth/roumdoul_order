<?php

namespace App\Support;

use App\Enums\StaffRole;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Discounts, voids and refunds need an owner or manager to type their PIN on
 * the cashier's screen. Returns the person who approved, for the audit log.
 * Five wrong tries per minute per person at the screen, then a short wait.
 */
final class ManagerPin
{
    public static function approve(int $companyId, ?string $pin, ?User $askedBy = null): User
    {
        $key = 'manager-pin|'.$companyId.'|'.($askedBy?->id ?? request()?->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['pin' => 'Too many wrong PINs. Please wait a minute.']);
        }

        if (is_string($pin) && preg_match('/^\d{4,6}$/', $pin)) {
            $managers = Membership::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->whereIn('role', [StaffRole::Owner->value, StaffRole::Manager->value])
                ->whereNotNull('pin_hash')
                ->with('user')
                ->get();

            foreach ($managers as $membership) {
                if ($membership->user?->is_active && Hash::check($pin, $membership->pin_hash)) {
                    RateLimiter::clear($key);

                    return $membership->user;
                }
            }
        }

        RateLimiter::hit($key, 60);

        throw ValidationException::withMessages(['pin' => 'Wrong manager PIN.']);
    }
}
