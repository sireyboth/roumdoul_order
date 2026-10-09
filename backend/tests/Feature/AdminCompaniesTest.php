<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminCompaniesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->company = Company::query()->where('slug', 'demo-cafe')->firstOrFail();
        $this->actingAs(User::query()->where('email', 'admin@roumdoul.test')->firstOrFail());
        Filament::setCurrentPanel('admin');
    }

    public function test_admin_changes_a_restaurant_plan(): void
    {
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();

        Livewire::test(ListCompanies::class)
            ->callTableAction('changePlan', $this->company, ['plan_id' => $pro->id])
            ->assertHasNoTableActionErrors();

        $company = $this->company->fresh();
        $this->assertSame($pro->id, $company->subscription->plan_id);
        $this->assertSame(3, $company->limitFor('branches'));
    }

    public function test_change_plan_creates_a_subscription_when_missing(): void
    {
        $this->company->subscription()->delete();
        $chain = Plan::query()->where('code', 'chain')->firstOrFail();

        Livewire::test(ListCompanies::class)
            ->callTableAction('changePlan', $this->company, ['plan_id' => $chain->id])
            ->assertHasNoTableActionErrors();

        $this->assertSame($chain->id, $this->company->fresh()->subscription->plan_id);
        $this->assertNull($this->company->fresh()->limitFor('branches'));
    }

    public function test_trial_length_comes_from_config(): void
    {
        config(['app.trial_days' => 7]);
        $this->travelTo(now()->startOfDay());

        $company = app(CompanyProvisioner::class)->create(['name' => 'Seven Day Café'], User::factory()->create());

        $this->assertTrue($company->trial_ends_at->equalTo(now()->addDays(7)));
    }
}
