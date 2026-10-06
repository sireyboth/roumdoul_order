<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Filament\App\Resources\Orders\Pages\ViewOrder;
use App\Models\Company;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\Billing\ShiftService;
use App\Services\CompanyProvisioner;
use App\Services\Ordering\OrderPlacer;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BackOfficeAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->company = Company::query()->where('slug', 'demo-cafe')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@roumdoul.test')->firstOrFail();
    }

    public function test_owner_can_open_every_back_office_page(): void
    {
        $this->actingAs($this->owner);

        foreach (['', '/branches', '/dining-tables', '/categories', '/menu-items', '/option-groups', '/staff', '/orders', '/shifts', '/profile'] as $path) {
            $this->get('/app/demo-cafe'.$path)->assertOk();
        }
    }

    public function test_branch_edit_page_with_menu_availability_loads(): void
    {
        $branch = $this->company->branches()->first();

        $this->actingAs($this->owner)
            ->get("/app/demo-cafe/branches/{$branch->id}/edit")
            ->assertOk();
    }

    public function test_order_page_shows_and_cancels_with_reason(): void
    {
        $table = $this->company->diningTables()->firstOrFail();
        $rice = MenuItem::query()->where('name_en', 'Khmer rice noodles')->firstOrFail();
        $order = app(OrderPlacer::class)->place($table, [['menu_item_id' => $rice->id, 'quantity' => 2]]);

        $this->actingAs($this->owner)->get("/app/demo-cafe/orders/{$order->id}")->assertOk()->assertSee('Khmer rice noodles');

        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->callAction('cancel', ['reason' => 'Kitchen ran out'])
            ->assertHasNoActionErrors();

        $this->assertSame('cancelled', $order->fresh()->status->value);
        $this->assertSame('Kitchen ran out', $order->fresh()->cancel_reason);
    }

    public function test_shift_page_shows_the_drawer_and_hides_other_restaurants(): void
    {
        $branch = $this->company->branches()->firstOrFail();
        $cashier = User::query()->where('email', 'cashier@roumdoul.test')->firstOrFail();
        $shifts = app(ShiftService::class);
        $shift = $shifts->open($branch, $cashier, 2000, 40000);
        $shifts->addMovement($shift, 'out', 500, 'USD', 'Ice delivery', $cashier);
        $shifts->close($shift, 1400, 40000, null, $cashier);

        // Made before any back-office request: Filament would stamp new rows with the current tenant.
        $stranger = User::factory()->create();
        $rival = app(CompanyProvisioner::class)->create(['name' => 'Rival Café', 'slug' => 'rival'], $stranger);
        $rivalShift = $shifts->open($rival->branches()->firstOrFail(), $stranger, 0, 0);

        $this->actingAs($this->owner)->get('/app/demo-cafe/shifts')->assertOk()->assertSee('−$1.00', false);
        $this->actingAs($this->owner)->get("/app/demo-cafe/shifts/{$shift->id}")->assertOk()->assertSee('Ice delivery')->assertSee('$15.00');
        // Shown in Phnom Penh time, not UTC.
        $this->actingAs($this->owner)->get('/app/demo-cafe/shifts')->assertSee($shift->opened_at->timezone('Asia/Phnom_Penh')->format('d M H:i'));

        $this->actingAs($this->owner)->get("/app/demo-cafe/shifts/{$rivalShift->id}")->assertNotFound();
    }

    public function test_owner_cannot_open_another_restaurant(): void
    {
        app(CompanyProvisioner::class)->create(['name' => 'Rival Café', 'slug' => 'rival'], User::factory()->create());

        $this->actingAs($this->owner)->get('/app/rival')->assertNotFound();
    }

    public function test_kitchen_staff_cannot_use_the_back_office(): void
    {
        $cook = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $cook->id, 'role' => StaffRole::Kitchen]);

        $this->assertFalse($cook->canAccessTenant($this->company));
        $this->assertCount(0, $cook->getTenants(Filament::getPanel('app')));
    }

    public function test_only_platform_admins_open_admin_panel(): void
    {
        $this->actingAs($this->owner)->get('/admin')->assertForbidden();

        $admin = User::query()->where('email', 'admin@roumdoul.test')->firstOrFail();
        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/admin/companies')->assertOk();
        $this->actingAs($admin)->get('/admin/plans')->assertOk();
    }

    public function test_plan_limit_blocks_extra_tables(): void
    {
        $plan = $this->company->subscription->plan;
        $plan->update(['max_tables' => $this->company->diningTables()->count()]);

        $this->assertTrue($this->company->fresh()->hasReachedLimit('tables'));
    }
}
