<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Starting price list. Placeholder numbers: change them in /admin > Plans
 * once you've talked to restaurant owners.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'starter', 'name' => 'Starter', 'sort_order' => 1,
                'description' => 'One small café or restaurant.',
                'max_branches' => 1, 'max_tables' => 15, 'max_staff' => 5,
                'price_monthly_cents' => 1900, 'price_yearly_cents' => 19000,
                'features' => ['telegram', 'printing', 'export'],
            ],
            [
                'code' => 'pro', 'name' => 'Pro', 'sort_order' => 2,
                'description' => 'Busy restaurants that want every feature.',
                'max_branches' => 3, 'max_tables' => 60, 'max_staff' => 30,
                'price_monthly_cents' => 3900, 'price_yearly_cents' => 39000,
                'features' => ['telegram', 'printing', 'export', 'khqr_auto', 'upsell', 'combos', 'kiosk'],
            ],
            [
                'code' => 'chain', 'name' => 'Chain', 'sort_order' => 3,
                'description' => 'Many branches, compared side by side.',
                'max_branches' => null, 'max_tables' => null, 'max_staff' => null,
                'price_monthly_cents' => 9900, 'price_yearly_cents' => 99000,
                'features' => ['telegram', 'printing', 'export', 'khqr_auto', 'upsell', 'combos', 'kiosk', 'multi_branch_reports'],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(['code' => $plan['code']], $plan + ['currency' => 'USD', 'is_active' => true]);
        }
    }
}
