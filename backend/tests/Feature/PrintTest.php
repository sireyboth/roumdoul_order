<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing\BillService;
use App\Services\Billing\ShiftService;
use App\Services\Ordering\OrderPlacer;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrintTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private DiningTable $table;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $company = Company::query()->where('slug', 'demo-cafe')->firstOrFail();
        $this->branch = $company->branches()->firstOrFail();
        $this->table = $company->diningTables()->where('name', 'T1')->firstOrFail();
        $this->cashier = User::query()->where('email', 'cashier@roumdoul.test')->firstOrFail();
    }

    private function token(string $email): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/staff/login', ['email' => $email, 'password' => 'password'])->json('data.token');
    }

    /** Fried rice (kitchen) + small iced latte (bar). */
    private function order(): Order
    {
        $rice = MenuItem::query()->where('name_en', 'Fried rice')->with('optionGroups.options')->firstOrFail();
        $latte = MenuItem::query()->where('name_en', 'Iced latte')->with('optionGroups.options')->firstOrFail();
        $pick = fn (MenuItem $i, array $n) => $i->optionGroups->flatMap->options->whereIn('name_en', $n)->pluck('id')->all();

        return app(OrderPlacer::class)->place($this->table, [
            ['menu_item_id' => $rice->id, 'quantity' => 1, 'option_ids' => $pick($rice, ['Not spicy'])],
            ['menu_item_id' => $latte->id, 'quantity' => 1, 'option_ids' => $pick($latte, ['Small', 'Normal sugar', 'Normal ice'])],
        ], idempotencyKey: (string) Str::uuid());
    }

    public function test_receipt_merges_rounds_and_shows_discount_payment_and_branch_text(): void
    {
        $this->branch->update(['receipt_header' => 'VAT TIN K001-123', 'receipt_footer' => 'Thank you!']);
        $first = $this->order();
        $this->order(); // same items again in a second round

        $bills = app(BillService::class);
        $bill = $bills->openFor($first->session, $this->cashier);
        $bills->addDiscount($bill, 'percent', 1000, 'Regular', '1234', $this->cashier);
        app(ShiftService::class)->open($this->branch, $this->cashier, 0, 0);
        $bills->addPayment($bill, ['idempotency_key' => (string) Str::uuid(), 'method' => 'cash', 'tendered_amount' => 1000, 'tendered_currency' => 'USD'], $this->cashier);

        $res = $this->withToken($this->token('cashier@roumdoul.test'))
            ->getJson("/api/staff/bills/{$bill->id}/receipt")
            ->assertOk()
            ->assertJsonPath('data.branch.header', 'VAT TIN K001-123')
            ->assertJsonPath('data.branch.footer', 'Thank you!')
            ->assertJsonPath('data.bill.status', 'paid')
            ->assertJsonPath('data.bill.table', 'T1')
            ->assertJsonPath('data.bill.subtotal', 1050)
            ->assertJsonPath('data.bill.adjustments.0.label', 'Discount 10%')
            ->assertJsonPath('data.bill.adjustments.0.amount', 105)
            ->assertJsonPath('data.bill.total', 945)
            ->assertJsonPath('data.payments.0.change_amount', 55)
            ->assertJsonPath('data.cashier', 'Demo Cashier')
            ->assertJsonCount(2, 'data.lines');

        $this->assertSame(2, $res->json('data.lines.0.quantity'));
        $this->assertSame(600, $res->json('data.lines.0.line_total'));
    }

    public function test_waiter_prints_the_bill_but_kitchen_cannot(): void
    {
        $order = $this->order();
        $bill = app(BillService::class)->openFor($order->session, $this->cashier);

        $this->withToken($this->token('waiter@roumdoul.test'))->getJson("/api/staff/bills/{$bill->id}/receipt")
            ->assertOk()->assertJsonPath('data.bill.status', 'open')->assertJsonPath('data.bill.remaining', 525);
        $this->withToken($this->token('kitchen@roumdoul.test'))->getJson("/api/staff/bills/{$bill->id}/receipt")->assertForbidden();
    }

    public function test_tickets_per_station_and_auto_print_setting(): void
    {
        $order = $this->order();
        $token = $this->token('kitchen@roumdoul.test');

        $this->withToken($token)->getJson("/api/staff/orders/{$order->id}/ticket?station=kitchen")
            ->assertOk()
            ->assertJsonPath('data.number', $order->number)
            ->assertJsonPath('data.table', 'T1')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name.en', 'Fried rice');

        $this->withToken($token)->getJson("/api/staff/orders/{$order->id}/ticket?station=bar")
            ->assertJsonPath('data.items.0.name.en', 'Iced latte');

        $this->withToken($token)->getJson("/api/staff/branches/{$this->branch->id}/board")
            ->assertJsonPath('data.settings.auto_print_kitchen', false);

        $this->branch->update(['auto_print_kitchen' => true]);
        $this->withToken($token)->getJson("/api/staff/branches/{$this->branch->id}/board")
            ->assertJsonPath('data.settings.auto_print_kitchen', true);
    }
}
