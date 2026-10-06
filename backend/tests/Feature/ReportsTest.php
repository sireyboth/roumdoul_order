<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\App\Resources\DailySales\Pages\ListDailySales;
use App\Filament\App\Widgets\BestSellers;
use App\Filament\App\Widgets\PaymentMethodsChart;
use App\Filament\App\Widgets\SalesChart;
use App\Filament\App\Widgets\SalesOverview;
use App\Models\Bill;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DailyBranchSale;
use App\Models\DailyItemSale;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing\BillService;
use App\Services\Billing\ShiftService;
use App\Services\CompanyProvisioner;
use App\Services\Ordering\OrderPlacer;
use App\Services\Reports\CsvExport;
use App\Services\Reports\DailySales;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $cashier;

    private BillService $bills;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->company = Company::query()->where('slug', 'demo-cafe')->firstOrFail();
        $this->branch = $this->company->branches()->firstOrFail();
        $this->cashier = User::query()->where('email', 'cashier@roumdoul.test')->firstOrFail();
        $this->bills = app(BillService::class);
        app(ShiftService::class)->open($this->branch, $this->cashier, 0, 0);
    }

    /** Fried rice $3.00 + small iced latte $2.25 = $5.25 */
    private function order(string $tableName): Order
    {
        $table = DiningTable::query()->where('branch_id', $this->branch->id)->where('name', $tableName)->firstOrFail();
        $rice = MenuItem::query()->where('name_en', 'Fried rice')->with('optionGroups.options')->firstOrFail();
        $latte = MenuItem::query()->where('name_en', 'Iced latte')->with('optionGroups.options')->firstOrFail();
        $pick = fn (MenuItem $i, array $n) => $i->optionGroups->flatMap->options->whereIn('name_en', $n)->pluck('id')->all();

        return app(OrderPlacer::class)->place($table, [
            ['menu_item_id' => $rice->id, 'quantity' => 1, 'option_ids' => $pick($rice, ['Not spicy'])],
            ['menu_item_id' => $latte->id, 'quantity' => 1, 'option_ids' => $pick($latte, ['Small', 'Normal sugar', 'Normal ice'])],
        ], idempotencyKey: (string) Str::uuid());
    }

    private function pay(Bill $bill, array $input): void
    {
        $this->bills->addPayment($bill, $input + ['idempotency_key' => (string) Str::uuid()], $this->cashier);
    }

    /** Two paid bills (one with 10% off, one later refunded in part), one open bill, one cancelled order. */
    private function busyDay(): string
    {
        $b1 = $this->bills->openFor($this->order('T1')->session, $this->cashier);
        $this->bills->addDiscount($b1, 'percent', 1000, 'Regular', '1234', $this->cashier);
        $this->pay($b1, ['method' => 'cash', 'tendered_amount' => 1000, 'tendered_currency' => 'USD']); // 472

        $b2 = $this->bills->openFor($this->order('T2')->session, $this->cashier);
        $this->pay($b2, ['method' => 'khqr', 'amount' => 300]);
        $this->pay($b2, ['method' => 'cash', 'tendered_amount' => 225, 'tendered_currency' => 'USD']);
        $khqr = $b2->payments()->where('method', 'khqr')->firstOrFail();
        $this->bills->refund($khqr, 'Charged twice', '1234', $this->cashier);

        $this->order('T3'); // still open, not a sale yet
        $this->order('T4')->moveTo(OrderStatus::Cancelled, $this->cashier, 'Changed mind');

        return $b1->business_date->toDateString();
    }

    public function test_rebuild_counts_paid_bills_methods_refunds_and_items(): void
    {
        $date = $this->busyDay();

        $row = app(DailySales::class)->rebuild($this->branch->id, $date);

        $this->assertSame(2, $row->bills_count);
        $this->assertSame(3, $row->orders_count); // T1, T2, T3
        $this->assertSame(1, $row->cancelled_count);
        $this->assertSame(1050, $row->gross);
        $this->assertSame(53, $row->discounts);
        $this->assertSame(472 + 525, $row->net);
        $this->assertSame(472 + 225, $row->cash);
        $this->assertSame(0, $row->khqr); // refunded, so not counted as taken
        $this->assertSame(300, $row->refunds);
        $this->assertSame(4, $row->items_count);

        $items = DailyItemSale::query()->where('branch_id', $this->branch->id)->orderBy('name_en')->get();
        $this->assertSame(['Fried rice', 'Iced latte'], $items->pluck('name_en')->all());
        $this->assertSame([2, 2], $items->pluck('quantity')->all());
        $this->assertSame([600, 450], $items->pluck('amount')->all());

        // Rebuilding again gives the same numbers (no doubling).
        app(DailySales::class)->rebuild($this->branch->id, $date);
        $this->assertSame(1, DailyBranchSale::query()->count());
        $this->assertSame(2, DailyItemSale::query()->count());
    }

    public function test_paying_through_the_api_updates_the_report_after_the_response(): void
    {
        $order = $this->order('T1');
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/staff/login', ['email' => 'cashier@roumdoul.test', 'password' => 'password'])->json('data.token');
        $billId = $this->withToken($token)->postJson("/api/staff/sessions/{$order->table_session_id}/bill")->json('data.id');

        $this->withToken($token)->postJson("/api/staff/bills/{$billId}/payments", [
            'idempotency_key' => (string) Str::uuid(), 'method' => 'khqr',
        ])->assertCreated();

        $row = DailyBranchSale::query()->sole();
        $this->assertSame(525, $row->net);
        $this->assertSame(525, $row->khqr);
    }

    public function test_rebuild_command(): void
    {
        $date = $this->busyDay();
        DailyBranchSale::query()->delete();

        $this->artisan('reports:rebuild', ['date' => $date])
            ->expectsOutputToContain('1 branch-day(s) rebuilt.')
            ->assertSuccessful();
        $this->artisan('reports:rebuild', ['date' => '7/10/2026'])->assertFailed();

        $this->assertSame(997, DailyBranchSale::query()->sole()->net);
    }

    public function test_daily_summary_goes_to_telegram_once_after_the_day_ends(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        $this->company->update(['telegram_chat_id' => '-100123']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $date = $this->busyDay();

        // Same business day: nothing to send yet.
        $this->artisan('reports:daily-summary')->assertSuccessful();
        Http::assertNothingSent();

        $this->travel(1)->days();
        $this->artisan('reports:daily-summary')->expectsOutputToContain('1 summary message(s) sent.');
        $this->artisan('reports:daily-summary')->expectsOutputToContain('0 summary message(s) sent.');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['chat_id'] === '-100123'
            && str_contains($request['text'], 'Net sales: <b>$9.97</b>')
            && str_contains($request['text'], 'Fried rice × 2'));
        $this->assertNotNull(DailyBranchSale::query()->whereDate('business_date', $date)->sole()->summary_sent_at);
    }

    public function test_csv_exports_are_limited_to_the_company(): void
    {
        $date = $this->busyDay();
        app(DailySales::class)->rebuild($this->branch->id, $date);

        $export = new CsvExport($this->company);
        $orders = iterator_to_array($export->orders($date, $date), false);
        $payments = iterator_to_array($export->payments($date, $date), false);
        $items = iterator_to_array($export->items($date, $date), false);

        $this->assertCount(1 + 4, $orders);
        $this->assertSame('1 x Fried rice; 1 x Iced latte', $orders[1][7]);
        $this->assertSame(5.25, $orders[1][8]);
        $this->assertCount(1 + 3, $payments);
        $this->assertSame('refunded', collect($payments)->firstWhere(5, 'KHQR')[13]);
        $this->assertCount(1 + 2, $items);

        $other = User::factory()->create();
        $rival = app(CompanyProvisioner::class)->create(['name' => 'Rival'], $other);
        $this->assertCount(1, iterator_to_array((new CsvExport($rival))->orders($date, $date), false));
    }

    public function test_dashboard_and_daily_sales_page(): void
    {
        $date = $this->busyDay();
        app(DailySales::class)->rebuild($this->branch->id, $date);
        $owner = User::query()->where('email', 'owner@roumdoul.test')->firstOrFail();

        $this->actingAs($owner)->get('/app/demo-cafe')->assertOk();
        $this->actingAs($owner)->get('/app/demo-cafe/daily-sales')->assertOk()->assertSee('$9.97');

        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);

        // Dashboard widgets load lazily, so they are checked one by one.
        Livewire::test(SalesOverview::class)->assertSee('Sales today')->assertSee('$9.97')->assertSee('2 bills');
        Livewire::test(BestSellers::class)->assertSee('Fried rice')->assertSee('$6.00');
        Livewire::test(SalesChart::class)->assertOk();
        Livewire::test(PaymentMethodsChart::class)->assertOk();

        Livewire::test(ListDailySales::class)
            ->callAction('export_orders', data: ['from' => $date, 'to' => $date])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded("demo-cafe-orders-{$date}-to-{$date}.csv");
    }
}
