<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Services\Ordering\OrderPlacer;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private DiningTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->company = Company::query()->where('slug', 'demo-cafe')->firstOrFail();
        $this->table = $this->company->diningTables()->where('name', 'T1')->firstOrFail();
    }

    private function token(string $email): string
    {
        // The test app keeps the last signed-in user between requests; reset so each token stands alone.
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/staff/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()
            ->json('data.token');
    }

    private function placeOrder(): Order
    {
        $rice = MenuItem::query()->where('name_en', 'Fried rice')->with('optionGroups.options')->firstOrFail();
        $latte = MenuItem::query()->where('name_en', 'Iced latte')->with('optionGroups.options')->firstOrFail();
        $pick = fn (MenuItem $i, array $n) => $i->optionGroups->flatMap->options->whereIn('name_en', $n)->pluck('id')->all();

        return app(OrderPlacer::class)->place($this->table, [
            ['menu_item_id' => $rice->id, 'quantity' => 1, 'option_ids' => $pick($rice, ['Not spicy'])],
            ['menu_item_id' => $latte->id, 'quantity' => 1, 'option_ids' => $pick($latte, ['Small', 'Normal sugar', 'Normal ice'])],
        ], idempotencyKey: 'test-order-0001');
    }

    public function test_login_returns_the_branches_this_person_works_at(): void
    {
        $this->postJson('/api/staff/login', ['email' => 'kitchen@roumdoul.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.branches.0.name', 'BKK1')
            ->assertJsonPath('data.branches.0.role', 'kitchen');

        $this->postJson('/api/staff/login', ['email' => 'kitchen@roumdoul.test', 'password' => 'wrong'])
            ->assertStatus(422);

        // Platform admin has no restaurant job
        $this->postJson('/api/staff/login', ['email' => 'admin@roumdoul.test', 'password' => 'password'])
            ->assertStatus(422);
    }

    public function test_kitchen_board_shows_only_its_station_and_moves_orders(): void
    {
        $order = $this->placeOrder();
        $token = $this->token('kitchen@roumdoul.test');
        $branchId = $this->table->branch_id;

        $board = $this->withToken($token)->getJson("/api/staff/branches/{$branchId}/board?station=kitchen")->assertOk();
        $board->assertJsonCount(1, 'data.orders')
            ->assertJsonCount(1, 'data.orders.0.items')
            ->assertJsonPath('data.orders.0.items.0.name.en', 'Fried rice')
            ->assertJsonPath('data.orders.0.table', 'T1');

        $this->withToken($token)->postJson("/api/staff/orders/{$order->id}/status", ['status' => 'accepted'])->assertOk();
        $this->withToken($token)->postJson("/api/staff/orders/{$order->id}/status", ['status' => 'ready'])
            ->assertOk()->assertJsonPath('data.status', 'ready');

        // Not allowed: back to placed
        $this->withToken($token)->postJson("/api/staff/orders/{$order->id}/status", ['status' => 'placed'])->assertStatus(422);
    }

    public function test_kitchen_cannot_cancel_but_cashier_can_with_a_reason(): void
    {
        $order = $this->placeOrder();

        $this->withToken($this->token('kitchen@roumdoul.test'))
            ->postJson("/api/staff/orders/{$order->id}/status", ['status' => 'cancelled', 'reason' => 'x'])
            ->assertForbidden();

        $cashier = $this->token('cashier@roumdoul.test');
        $this->withToken($cashier)->postJson("/api/staff/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertStatus(422);
        $this->withToken($cashier)->postJson("/api/staff/orders/{$order->id}/status", ['status' => 'cancelled', 'reason' => 'Customer left'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['event' => 'order.cancelled', 'reason' => 'Customer left', 'subject_id' => $order->id]);
    }

    public function test_staff_of_another_restaurant_see_nothing(): void
    {
        $order = $this->placeOrder();
        $stranger = User::factory()->create(['password' => 'password']);
        app(CompanyProvisioner::class)->create(['name' => 'Rival'], $stranger);
        $token = $this->token($stranger->email);

        $this->withToken($token)->getJson("/api/staff/branches/{$this->table->branch_id}/board")->assertForbidden();
        $this->withToken($token)->postJson("/api/staff/orders/{$order->id}/status", ['status' => 'accepted'])->assertForbidden();
    }

    public function test_waiter_handles_calls_and_sold_out(): void
    {
        $url = "/api/public/tables/{$this->table->qr_token}/requests";
        $this->postJson($url, ['type' => 'waiter'])->assertCreated();

        $token = $this->token('waiter@roumdoul.test');
        $branchId = $this->table->branch_id;

        $requestId = $this->withToken($token)->getJson("/api/staff/branches/{$branchId}/board")
            ->assertJsonPath('data.requests.0.type', 'waiter')
            ->json('data.requests.0.id');

        $this->withToken($token)->postJson("/api/staff/requests/{$requestId}/done")->assertOk();
        $this->withToken($token)->getJson("/api/staff/branches/{$branchId}/board")->assertJsonCount(0, 'data.requests');

        $latte = MenuItem::query()->where('name_en', 'Iced latte')->firstOrFail();
        $this->withToken($token)->postJson("/api/staff/branches/{$branchId}/menu/{$latte->id}/sold-out", ['sold_out' => true])
            ->assertOk()->assertJsonPath('data.sold_out', true);

        $item = collect($this->getJson("/api/public/tables/{$this->table->qr_token}")->json('data.categories'))
            ->pluck('items')->flatten(1)->firstWhere('id', $latte->id);
        $this->assertNotNull($item['sold_out_until']);
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        $this->getJson("/api/staff/branches/{$this->table->branch_id}/board")->assertUnauthorized();
    }
}
