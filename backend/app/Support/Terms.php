<?php

namespace App\Support;

use App\Models\Company;
use App\Models\TermsAcceptance;
use App\Models\User;
use Filament\Forms\Components\Checkbox;
use Illuminate\Support\HtmlString;

/**
 * The restaurant terms (frontend /terms, text in frontend/src/lib/terms.ts). Owners tick
 * "I agree" at sign-up and before every plan change; each tick is stored as proof.
 * Changing the terms = change TERMS_VERSION here (config tok.terms_version) and in terms.ts.
 */
final class Terms
{
    public const SIGNUP = 'signup';

    public const PLAN_CHANGE = 'plan_change';

    public static function version(): string
    {
        return (string) config('tok.terms_version');
    }

    public static function url(): string
    {
        return config('tok.frontend_url').'/terms';
    }

    /** The required "I agree" tick box with a link to the full terms (opens in a new tab). */
    public static function checkbox(string $name = 'accept_terms'): Checkbox
    {
        return Checkbox::make($name)
            ->label(new HtmlString(
                'I have read and agree to the <a href="'.e(self::url()).'" target="_blank" rel="noopener" class="underline font-semibold">Terms and conditions</a> (version '.e(self::version()).').'
            ))
            ->accepted()
            ->validationMessages(['accepted' => 'Please read and tick the Terms to continue.'])
            ->dehydrated(false);
    }

    public static function record(Company $company, ?User $user, string $context, ?string $detail = null): TermsAcceptance
    {
        $request = request();

        return TermsAcceptance::query()->create([
            'company_id' => $company->id,
            'user_id' => $user?->id,
            'version' => self::version(),
            'context' => $context,
            'detail' => $detail ? mb_substr($detail, 0, 255) : null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }
}
