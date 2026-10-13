<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Filament\Admin\Resources\PlanFeatures\Pages\ListPlanFeatures;
use App\Filament\Admin\Resources\Plans\Pages\ListPlans;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PublicPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_page_gets_active_plans_in_order_with_prices_in_cents(): void
    {
        $this->seed(PlanSeeder::class);
        Plan::query()->where('code', 'pro')->update(['price_monthly_cents' => 4500]);
        Plan::query()->create(['code' => 'old', 'name' => 'Old', 'is_active' => false, 'sort_order' => 0]);

        $this->getJson('/api/public/plans')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.code', 'starter')
            ->assertJsonPath('data.0.price_monthly', 1900)
            ->assertJsonPath('data.0.price_yearly', 19000)
            ->assertJsonPath('data.0.price_per_order', null)
            ->assertJsonPath('data.0.max_tables', 15)
            ->assertJsonPath('data.1.code', 'pro')
            ->assertJsonPath('data.1.price_monthly', 4500)
            ->assertJsonPath('data.2.code', 'chain')
            ->assertJsonPath('data.2.max_branches', null)
            ->assertJsonPath('data.2.features.7', ['key' => 'multi_branch_reports', 'name' => 'Compare branches side by side', 'coming_soon' => true])
            ->assertJsonPath('data.0.features.0', ['key' => 'telegram', 'name' => 'Telegram day-end sales summary', 'coming_soon' => false])
            ->assertJsonPath('data.3.code', 'per_order')
            ->assertJsonPath('data.3.price_per_order', 1)
            ->assertJsonPath('data.3.features', []);
    }

    public function test_it_needs_no_login_and_works_with_no_plans(): void
    {
        $this->getJson('/api/public/plans')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_platform_admin_sets_and_clears_the_price_per_order(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
        $plan = Plan::query()->where('code', 'per_order')->firstOrFail();
        $starter = Plan::query()->where('code', 'starter')->firstOrFail();

        Livewire::test(ListPlans::class)
            ->callTableAction('edit', $plan, ['price_per_order_cents' => '0.02'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('edit', $starter, ['name' => 'Starter'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(2, $plan->fresh()->price_per_order_cents);
        $this->assertNull($starter->fresh()->price_per_order_cents);
    }

    public function test_coming_soon_comes_from_the_code_not_from_admin(): void
    {
        $this->seed(PlanSeeder::class);

        // No rows yet: default names, in code order, "coming soon" = not built.
        $chain = $this->getJson('/api/public/plans')->json('data.2.features');
        $this->assertSame(array_column(Feature::cases(), 'value'), array_column($chain, 'key'));
        foreach ($chain as $row) {
            $feature = Feature::from($row['key']);
            $this->assertSame($feature->getLabel(), $row['name']);
            $this->assertSame(! $feature->isBuilt(), $row['coming_soon']);
        }
    }

    public function test_features_appear_in_admin_by_themselves_and_admin_sets_name_and_order(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
        // A row left over from a feature that was removed from the code.
        PlanFeature::query()->create(['key' => 'gone', 'name' => 'Old feature', 'sort_order' => 99]);

        $page = Livewire::test(ListPlanFeatures::class);

        $this->assertSame(count(Feature::cases()) + 1, PlanFeature::query()->count());
        $feature = PlanFeature::query()->where('key', 'multi_branch_reports')->firstOrFail();
        $page->assertCanSeeTableRecords([$feature])
            ->assertCanNotSeeTableRecords(PlanFeature::query()->where('key', 'gone')->get())
            ->assertActionDoesNotExist('create')
            ->callTableAction('edit', $feature, ['name' => 'Branch comparison'])
            ->assertHasNoTableActionErrors();
        $feature->update(['sort_order' => 0]);

        // Renamed and moved first, but still "coming soon" because it isn't built.
        $this->getJson('/api/public/plans')
            ->assertJsonPath('data.2.features.0', ['key' => 'multi_branch_reports', 'name' => 'Branch comparison', 'coming_soon' => true]);
    }

    public function test_keys_not_in_the_code_are_skipped(): void
    {
        $this->seed(PlanSeeder::class);
        PlanFeature::query()->create(['key' => 'gone', 'name' => 'Old feature']);
        Plan::query()->where('code', 'starter')->firstOrFail()
            ->update(['features' => ['gone', 'kiosk', 'nothing', 'telegram']]);

        $this->getJson('/api/public/plans')
            ->assertJsonCount(2, 'data.0.features')
            ->assertJsonPath('data.0.features.0.key', 'telegram')
            ->assertJsonPath('data.0.features.1.key', 'kiosk');
    }
}
