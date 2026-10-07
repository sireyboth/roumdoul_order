<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Filament\App\Resources\Staff\StaffResource;
use App\Filament\App\Widgets\SalesOverview;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing\BillService;
use App\Services\Billing\ShiftService;
use App\Services\Ordering\OrderPlacer;
use App\Services\Reports\CsvExport;
use App\Services\Reports\DailySales;
use App\Support\StaffAccess;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Owners see every branch; everyone else only the branches ticked for them. */
class BranchAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $bkk;

    private Branch $tk;

    private User $owner;

    private User $tkManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->company = Company::query()->where('slug', 'demo-cafe')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@roumdoul.test')->firstOrFail();
        $this->bkk = $this->company->branches()->firstOrFail();

        // A second branch with one table, and a manager who works only there.
        $this->tk = Branch::query()->create(['company_id' => $this->company->id, 'name' => 'Toul Kork', 'code' => 'TK', 'sort_order' => 2]);
        DiningTable::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->tk->id, 'name' => 'K1']);

        $this->tkManager = User::factory()->create(['email' => 'tk@roumdoul.test', 'password' => 'password', 'is_active' => true]);
        $this->company->memberships()->create(['user_id' => $this->tkManager->id, 'role' => StaffRole::Manager, 'is_active' => true]);
        DB::table('branch_user')->insert(['branch_id' => $this->tk->id, 'user_id' => $this->tkManager->id]);
    }

    /** A paid $2.50 bill at the first table of a branch. */
    private function sale(Branch $branch): Order
    {
        $table = DiningTable::query()->where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $noodles = MenuItem::query()->where('name_en', 'Khmer rice noodles')->firstOrFail();
        $order = app(OrderPlacer::class)->place($table, [['menu_item_id' => $noodles->id, 'quantity' => 1]], idempotencyKey: (string) Str::uuid());

        $cashier = $this->owner;
        if (! ShiftService::openFor($branch->id)) {
            app(ShiftService::class)->open($branch, $cashier, 0, 0);
        }
        $bills = app(BillService::class);
        $bill = $bills->openFor($order->session, $cashier);
        $bills->addPayment($bill, ['idempotency_key' => (string) Str::uuid(), 'method' => 'khqr'], $cashier);
        app(DailySales::class)->rebuild($branch->id, $bill->business_date->toDateString());

        return $order;
    }

    private function token(string $email): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/staff/login', ['email' => $email, 'password' => 'password'])->json('data.token');
    }

    public function test_adding_a_second_branch_keeps_existing_staff_at_the_first(): void
    {
        $cashier = User::query()->where('email', 'cashier@roumdoul.test')->firstOrFail();
        $membership = $this->company->memberships()->where('user_id', $cashier->id)->firstOrFail();

        $this->assertSame([$this->bkk->id], StaffAccess::branchIds($membership));
        $this->assertNull(StaffAccess::branchIds($this->company->memberships()->where('user_id', $this->owner->id)->firstOrFail()));
    }

    public function test_staff_screens_only_open_their_own_branch(): void
    {
        $this->postJson('/api/staff/login', ['email' => 'cashier@roumdoul.test', 'password' => 'password'])
            ->assertJsonCount(1, 'data.branches')
            ->assertJsonPath('data.branches.0.name', 'BKK1');

        $cashier = $this->token('cashier@roumdoul.test');
        $this->withToken($cashier)->getJson("/api/staff/branches/{$this->bkk->id}/tables")->assertOk();
        $this->withToken($cashier)->getJson("/api/staff/branches/{$this->tk->id}/tables")->assertForbidden();

        $manager = $this->token('tk@roumdoul.test');
        $this->withToken($manager)->getJson("/api/staff/branches/{$this->tk->id}/board")->assertOk();
        $this->withToken($manager)->getJson("/api/staff/branches/{$this->bkk->id}/board")->assertForbidden();

        // The owner works everywhere.
        $this->postJson('/api/staff/login', ['email' => 'owner@roumdoul.test', 'password' => 'password'])
            ->assertJsonCount(2, 'data.branches');
    }

    public function test_back_office_shows_a_manager_only_their_branch(): void
    {
        $bkkOrder = $this->sale($this->bkk);
        $tkOrder = $this->sale($this->tk);

        $this->actingAs($this->tkManager);
        $this->get('/app/demo-cafe/orders')->assertOk()->assertSee('K1')->assertDontSee('T1');
        $this->get("/app/demo-cafe/orders/{$tkOrder->id}")->assertOk();
        $this->get("/app/demo-cafe/orders/{$bkkOrder->id}")->assertNotFound();
        $this->get('/app/demo-cafe/branches')->assertOk()->assertSee('Toul Kork')->assertDontSee('BKK1');
        $this->get("/app/demo-cafe/branches/{$this->bkk->id}/edit")->assertNotFound();
        $this->get('/app/demo-cafe/dining-tables')->assertOk()->assertSee('K1')->assertDontSee('T6');
        $this->get('/app/demo-cafe/shifts')->assertOk()->assertSee('Toul Kork')->assertDontSee('BKK1');
        $this->get('/app/demo-cafe/daily-sales')->assertOk()->assertSee('Toul Kork')->assertDontSee('BKK1');

        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);

        // Dashboard: one $2.50 sale at Toul Kork for the manager, both for the owner.
        Livewire::test(SalesOverview::class)->assertSee('$2.50')->assertSee('1 bills');
        $this->actingAs($this->owner);
        Livewire::test(SalesOverview::class)->assertSee('$5.00')->assertSee('2 bills');

        $date = $bkkOrder->business_date->toDateString();
        $this->assertCount(1 + 1, iterator_to_array((new CsvExport($this->company, [$this->tk->id]))->orders($date, $date), false));
        $this->assertCount(1 + 0, iterator_to_array((new CsvExport($this->company, [$this->tk->id]))->orders($date, $date, $this->bkk->id), false));
        $this->assertCount(1 + 2, iterator_to_array((new CsvExport($this->company))->orders($date, $date), false));
    }

    public function test_managers_cannot_make_owners_or_widen_branches(): void
    {
        $this->actingAs($this->tkManager);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);

        $this->assertFalse(StaffResource::mayGiveRole('owner'));
        $this->assertTrue(StaffResource::mayGiveRole('cashier'));

        $ownerRow = $this->company->memberships()->where('user_id', $this->owner->id)->firstOrFail();
        $this->assertTrue(StaffResource::protectsOwner($ownerRow));

        // A cashier at both branches: the TK manager can only change the TK part.
        $both = User::factory()->create();
        $membership = $this->company->memberships()->create(['user_id' => $both->id, 'role' => StaffRole::Cashier, 'is_active' => true]);
        DB::table('branch_user')->insert([['branch_id' => $this->bkk->id, 'user_id' => $both->id], ['branch_id' => $this->tk->id, 'user_id' => $both->id]]);

        StaffResource::syncBranches($membership, []); // untick Toul Kork
        $this->assertSame([$this->bkk->id], StaffResource::branchIdsOf($membership));

        StaffResource::syncBranches($membership, [$this->bkk->id, $this->tk->id]); // BKK1 is not theirs to give
        $this->assertEqualsCanonicalizing([$this->bkk->id, $this->tk->id], StaffResource::branchIdsOf($membership));

        // The Staff list only shows people of the manager's branches (and the manager).
        $this->get('/app/demo-cafe/staff')->assertOk()->assertSee('tk@roumdoul.test')->assertDontSee('kitchen@roumdoul.test');
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner)->get('/app/demo-cafe/staff')->assertOk()->assertSee('kitchen@roumdoul.test');
    }
}
