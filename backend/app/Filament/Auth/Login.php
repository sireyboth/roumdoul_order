<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Support\SignInBlock;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Login for /admin and /app. Same as Filament's, except that when the password is
 * right but the account may not come in, it says why (and who can fix it) instead
 * of "These credentials do not match our records".
 */
class Login extends BaseLogin
{
    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        if ($user instanceof User) {
            $reason = Filament::getCurrentOrDefaultPanel()->getId() === 'admin'
                ? SignInBlock::forAdmin($user)
                : SignInBlock::forBackOffice($user);

            if ($reason !== null) {
                throw ValidationException::withMessages(['data.email' => $reason]);
            }
        }

        return parent::isUserAllowedToAccessPanel($user);
    }
}
