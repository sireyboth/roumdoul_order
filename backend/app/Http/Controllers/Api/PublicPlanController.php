<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Http\JsonResponse;

/** Price list for the public start page (tok.roumdoul.com). Prices are edited in /admin > Plans. */
class PublicPlanController extends Controller
{
    public function index(): JsonResponse
    {
        // Names and order from /admin > Plan features, "Coming soon" from the code (App\Enums\Feature).
        // Keys that are no longer in the code are skipped.
        $features = PlanFeature::catalog();

        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Plan $plan) => [
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                // null = unlimited
                'max_branches' => $plan->max_branches,
                'max_tables' => $plan->max_tables,
                'max_staff' => $plan->max_staff,
                // Minor units (cents), like every other amount.
                'price_monthly' => (int) $plan->price_monthly_cents,
                'price_yearly' => (int) $plan->price_yearly_cents,
                // Set only on a pay-per-order plan.
                'price_per_order' => $plan->price_per_order_cents,
                'currency' => $plan->currency,
                // In the Plan features order, whatever order they were ticked in.
                'features' => $features
                    ->only($plan->features ?? [])
                    ->map(fn (PlanFeature $feature) => [
                        'key' => $feature->key,
                        'name' => $feature->name,
                        'coming_soon' => ! $feature->built(),
                    ])
                    ->values(),
            ]);

        return response()->json(['data' => $plans]);
    }
}
