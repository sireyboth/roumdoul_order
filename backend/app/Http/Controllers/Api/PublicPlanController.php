<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

/** Price list for the public start page (tok.roumdoul.com). Prices are edited in /admin > Plans. */
class PublicPlanController extends Controller
{
    public function index(): JsonResponse
    {
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
                'features' => array_values($plan->features ?? []),
            ]);

        return response()->json(['data' => $plans]);
    }
}
