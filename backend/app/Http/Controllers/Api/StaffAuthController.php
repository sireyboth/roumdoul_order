<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\User;
use App\Support\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** Sign-in for the kitchen, waiter and cashier screens (Sanctum tokens). */
class StaffAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ]);

        $key = 'staff-login|'.strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 8)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Please wait a minute and try again.']);
        }

        $user = User::query()->where('email', strtolower($data['email']))->first();

        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Email or password is wrong.']);
        }

        RateLimiter::clear($key);

        $branches = $this->branchesFor($user);

        if ($branches === []) {
            throw ValidationException::withMessages(['email' => 'This account is not on the staff of any restaurant.']);
        }

        $token = $user->createToken($data['device_name'] ?? 'staff-screen', ['staff'])->plainTextToken;

        return response()->json(['data' => [
            'token' => $token,
            'user' => ['id' => $user->id, 'name' => $user->name],
            'branches' => $branches,
        ]]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'user' => ['id' => $user->id, 'name' => $user->name],
            'branches' => $this->branchesFor($user),
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    /** @return array<int, array<string, mixed>> */
    private function branchesFor(User $user): array
    {
        $memberships = Membership::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->with('company')
            ->get()
            ->filter(fn (Membership $m) => $m->company && $m->company->isOperational());

        return Branch::query()
            ->whereIn('company_id', $memberships->pluck('company_id'))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (Branch $branch) => StaffAccess::membership($user, $branch) !== null)
            ->map(function (Branch $branch) use ($memberships) {
                $membership = $memberships->firstWhere('company_id', $branch->company_id);

                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'company' => $membership->company->name,
                    'currency' => $membership->company->currency,
                    'role' => $membership->role->value,
                ];
            })
            ->values()
            ->all();
    }
}
