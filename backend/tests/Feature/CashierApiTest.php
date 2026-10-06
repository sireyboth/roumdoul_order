<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Services\Ordering\OrderPlacer;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashierApiTest extends TestCase
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
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/staff/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()
            ->json('data.token');
    }

    /** @return array<int, array<string, mixed>> Fried rice $3.00 + small iced latte $2.25 */
    private function lines(): array
    {
        $rice = MenuItem::query()->where('name_en', 'Fried rice')->with('optionGroups.options')->firstOrFail();
        $latte = MenuItem::query()->where('name_en', 'Iced latte')->with('optionGroups.options')->firstOrFail();
        $pick = fn (MenuItem $i, array $n) => $i->optionGroups->flatMap->options->whereIn('name_en', $n)->pluck('id')->values()->all();

        return [
            ['menu_item_id' => $rice->id, 'quantity' => 1, 'option_ids' => $pick($rice, ['Not spicy'])],
            ['menu_item_id' => $latte->id, 'quantity' => 1, 'option_ids' => $pick($latte, ['Small', 'Normal sugar', 'Normal ice'])],
        ];
    }

    private function placeOrder(): Order
    {
        return app(OrderPlacer::class)->place($this->table, $this->lines(), idempotencyKey: (string) Str::uuid());
    }

    public function test_cashier_sees_tables_with_amounts_and_bill_requests(): void
    {
        $order = $this->placeOrder();
        $this->postJson("/api/public/tables/{$this->table->qr_token}/requests", ['type' => 'bill'])->assertCreated();

        $res = $this->withToken($this->token('cashier@roumdoul.test'))
            ->getJson("/api/staff/branches/{$this->table->branch_id}/tables")
            ->assertOk()
            ->assertJsonCount(6, 'data.tables')
            ->assertJsonPath('data.currency', 'USD');

        $t1 = collect($res->json('data.tables'))->firstWhere('name', 'T1');
        $t2 = collect($res->json('data.tables'))->firstWhere('name', 'T2');

        $this->assertSame($order->table_session_id, $t1['session']['id']);
        $this->assertSame('bill_requested', $t1['session']['status']);
        $this->assertSame(525, $t1['session']['total']);
        $this->assertSame(1, $t1['session']['orders_count']);
        $this->assertNull($t2['session']);
    }

    public function test_full_payment_flow_through_the_api(): void
    {
        $order = $this->placeOrder();
        $token = $this->token('cashier@roumdoul.test');

        $bill = $this->withToken($token)->postJson("/api/staff/sessions/{$order->table_session_id}/bill")
            ->assertOk()
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.total', 525)
            ->assertJsonPath('data.remaining_khr', 21500)
            ->assertJsonPath('data.orders.0.number', $order->number)
            ->json('data');

        // 10% off with the owner's PIN: typed as "10"
        $this->withToken($token)->postJson("/api/staff/bills/{$bill['id']}/discounts", [
            'type' => 'percent', 'value' => 10, 'reason' => 'Regular', 'pin' => '1234',
        ])->assertOk()->assertJsonPath('data.discount_total', 53)->assertJsonPath('data.total', 472);

        $this->withToken($token)->postJson("/api/staff/bills/{$bill['id']}/discounts", [
            'type' => 'fixed', 'value' => 1, 'reason' => 'x', 'pin' => '0000',
        ])->assertStatus(422)->assertJsonValidationErrors('pin');

        // Riel cash: 472 cents = 19,352៛ → 19,400៛ asked; 20,000៛ handed over → 600៛ back
        $key = (string) Str::uuid();
        $payload = ['idempotency_key' => $key, 'method' => 'cash', 'tendered_amount' => 20000, 'tendered_currency' => 'KHR'];

        $this->withToken($token)->postJson("/api/staff/bills/{$bill['id']}/payments", $payload)
            ->assertCreated()
            ->assertJsonPath('data.payment.change_amount', 600)
            ->assertJsonPath('data.payment.change_currency', 'KHR')
            ->assertJsonPath('data.bill.status', 'paid');

        // The same tap again (bad wifi) does not take the money twice.
        $this->withToken($token)->postJson("/api/staff/bills/{$bill['id']}/payments", $payload)->assertOk();
        $this->assertSame(1, Bill::query()->findOrFail($bill['id'])->payments()->count());

        // The table is free again.
        $this->withToken($token)->getJson("/api/staff/branches/{$this->table->branch_id}/tables")
            ->assertJsonPath('data.tables.0.session', null);
    }

    public function test_refund_and_void_through_the_api(): void
    {
        $order = $this->placeOrder();
        $token = $this->token('cashier@roumdoul.test');
        $billId = $this->withToken($token)->postJson("/api/staff/sessions/{$order->table_session_id}/bill")->json('data.id');

        $paymentId = $this->withToken($token)->postJson("/api/staff/bills/{$billId}/payments", [
            'idempotency_key' => (string) Str::uuid(), 'method' => 'khqr', 'amount' => 200, 'reference' => 'ABA-1',
        ])->assertCreated()->json('data.payment.id');

        $this->withToken($token)->postJson("/api/staff/bills/{$billId}/void", ['reason' => 'Left', 'pin' => '1234'])
            ->assertStatus(422)->assertJsonValidationErrors('bill');

        $this->withToken($token)->postJson("/api/staff/payments/{$paymentId}/refund", ['reason' => 'Wrong amount', 'pin' => '1234'])
            ->assertOk()->assertJsonPath('data.paid_total', 0)->assertJsonPath('data.payments.0.status', 'refunded');

        $this->withToken($token)->postJson("/api/staff/bills/{$billId}/void", ['reason' => 'Left', 'pin' => '1234'])
            ->assertOk()->assertJsonPath('data.status', 'void');
    }

    public function test_kitchen_and_waiter_cannot_take_money(): void
    {
        $order = $this->placeOrder();

        foreach (['kitchen@roumdoul.test', 'waiter@roumdoul.test'] as $email) {
            $this->withToken($this->token($email))
                ->postJson("/api/staff/sessions/{$order->table_session_id}/bill")
                ->assertForbidden();
        }

        $this->assertSame(0, Bill::query()->count());
    }

    public function test_staff_of_another_restaurant_cannot_touch_bills(): void
    {
        $order = $this->placeOrder();
        $billId = $this->withToken($this->token('cashier@roumdoul.test'))
            ->postJson("/api/staff/sessions/{$order->table_session_id}/bill")->json('data.id');

        $stranger = User::factory()->create(['password' => 'password']);
        app(CompanyProvisioner::class)->create(['name' => 'Rival'], $stranger);
        $token = $this->token($stranger->email);

        $this->withToken($token)->getJson("/api/staff/bills/{$billId}")->assertForbidden();
        $this->withToken($token)->getJson("/api/staff/branches/{$this->table->branch_id}/tables")->assertForbidden();
        $this->withToken($token)->postJson("/api/staff/bills/{$billId}/payments", [
            'idempotency_key' => (string) Str::uuid(), 'method' => 'khqr', 'amount' => 100,
        ])->assertForbidden();
    }

    public function test_waiter_places_an_order_for_a_table(): void
    {
        $token = $this->token('waiter@roumdoul.test');
        $branchId = $this->table->branch_id;

        $this->withToken($token)->getJson("/api/staff/branches/{$branchId}/order-menu")
            ->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.categories.0.name.en', 'Coffee');

        $payload = ['idempotency_key' => (string) Str::uuid(), 'items' => $this->lines(), 'note' => 'Table by the window'];

        $this->withToken($token)->postJson("/api/staff/branches/{$branchId}/tables/{$this->table->id}/orders", $payload)
            ->assertCreated()
            ->assertJsonPath('data.subtotal', 525)
            ->assertJsonPath('data.source', 'waiter')
            ->assertJsonPath('data.table', 'T1');

        $this->withToken($token)->postJson("/api/staff/branches/{$branchId}/tables/{$this->table->id}/orders", $payload)->assertOk();

        $order = Order::query()->sole();
        $this->assertSame(User::query()->where('email', 'waiter@roumdoul.test')->value('id'), $order->placed_by_user_id);

        // The customer's phone sees it as part of the same visit.
        $this->getJson("/api/public/tables/{$this->table->qr_token}/session")
            ->assertJsonPath('data.orders.0.number', $order->number);
    }

    public function test_kitchen_cannot_place_orders_and_tables_must_match_the_branch(): void
    {
        $branchId = $this->table->branch_id;

        $this->withToken($this->token('kitchen@roumdoul.test'))
            ->postJson("/api/staff/branches/{$branchId}/tables/{$this->table->id}/orders", [
                'idempotency_key' => (string) Str::uuid(), 'items' => $this->lines(),
            ])->assertForbidden();

        $stranger = User::factory()->create(['password' => 'password']);
        $rival = app(CompanyProvisioner::class)->create(['name' => 'Rival'], $stranger);
        $rivalBranch = $rival->branches()->firstOrFail();

        // A demo table id under the rival's branch: the stranger works there, but the table is not theirs.
        $this->withToken($this->token($stranger->email))
            ->postJson("/api/staff/branches/{$rivalBranch->id}/tables/{$this->table->id}/orders", [
                'idempotency_key' => (string) Str::uuid(), 'items' => $this->lines(),
            ])->assertNotFound();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_customer_sees_the_bill_then_paid(): void
    {
        $order = $this->placeOrder();
        $url = "/api/public/tables/{$this->table->qr_token}/session";

        $this->getJson($url)->assertJsonPath('data.bill', null);

        $token = $this->token('cashier@roumdoul.test');
        $billId = $this->withToken($token)->postJson("/api/staff/sessions/{$order->table_session_id}/bill")->json('data.id');

        $this->getJson($url)->assertJsonPath('data.bill.total', 525)->assertJsonPath('data.bill.total_khr', 21500);

        $this->withToken($token)->postJson("/api/staff/bills/{$billId}/payments", [
            'idempotency_key' => (string) Str::uuid(), 'method' => 'cash', 'tendered_amount' => 525, 'tendered_currency' => 'USD',
        ])->assertCreated();

        $this->getJson($url)
            ->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.orders', [])
            ->assertJsonPath('data.last_visit.result', 'paid');

        // After 15 minutes the table forgets.
        $this->travel(16)->minutes();
        $this->getJson($url)->assertJsonPath('data.last_visit', null);
    }
}
