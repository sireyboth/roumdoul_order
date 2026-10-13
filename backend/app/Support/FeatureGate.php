<?php

namespace App\Support;

use App\Enums\Feature;
use App\Models\Company;
use App\Models\Plan;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Plan features in practice (docs/roadmap.md C1). The plan is read live through the
 * subscription, so ticking a feature on a plan applies to every restaurant on it at once.
 *
 * - Back office: a missing feature is shown **locked** with "Available on <plan>" (`allows()` + `hint()`).
 * - Staff screens and customer pages: it is **hidden** (the APIs send a flag or leave the data out).
 * - Server: staff APIs refuse with 403 + code `feature_not_in_plan` (`authorize()`).
 */
final class FeatureGate
{
    /** For the current back-office restaurant. */
    public static function allows(Feature $feature): bool
    {
        return (bool) Tenant::current()?->hasFeature($feature);
    }

    /** The cheapest plan that includes it, to say where to upgrade. */
    public static function cheapestPlanWith(Feature $feature): ?Plan
    {
        return Plan::query()
            ->where('is_active', true)
            ->whereNull('price_per_order_cents')
            ->orderBy('price_monthly_cents')
            ->get()
            ->first(fn (Plan $plan) => $plan->hasFeature($feature));
    }

    /** "Available on Pro. Upgrade on the Your plan page." */
    public static function hint(Feature $feature): string
    {
        $plan = self::cheapestPlanWith($feature);

        return ($plan ? "Available on the {$plan->name} plan." : 'Not in your plan.').' Upgrade on the "Your plan" page.';
    }

    public static function authorize(Company $company, Feature $feature): void
    {
        if (! $company->hasFeature($feature)) {
            throw new HttpResponseException(response()->json([
                'message' => 'This is not part of the restaurant\'s plan. The owner can upgrade in the back office.',
                'code' => 'feature_not_in_plan',
                'feature' => $feature->value,
            ], 403));
        }
    }
}
