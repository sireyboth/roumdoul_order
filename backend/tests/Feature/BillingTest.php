<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TableSession;
use App\Models\User;
use App\Services\Billing\BillService;
use App\Services\Billing\ShiftService;
use App\Services\Ordering\OrderPlacer;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private DiningTable $table;

    private User $cashier;

    private BillService $bills;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->company = Company::query()->where('slug', 'demo-cafe')->firstOrFail();
        $this->table = $this->company->diningTables()->where('name', 'T1')->firstOrFail();
        $this->cashier = User::query()->where('email', 'cashier@roumdoul.test')->firstOrFail();
        $this->bills = app(BillService::class);
        app(ShiftService::class)->open($this->table->branch, $this->cashier, 0, 0);
    }

    /** Fried rice $3.00 + small iced latte $2.25 = $5.25 */
    private function placeOrder(?DiningTable $table = null): Order
    {
        $rice = MenuItem::query()->where('name_en', 'Fried rice')->with('optionGroups.options')->firstOrFail();
        $latte = MenuItem::query()->where('name_en', 'Iced latte')->with('optionGroups.options')->firstOrFail();
        $pick = fn (MenuItem $i, array $n) => $i->optionGroups->flatMap->options->whereIn('name_en', $n)->pluck('id')->all();

        return app(OrderPlacer::class)->place($table ?? $this->table, [
            ['menu_item_id' => $rice->id, 'quantity' => 1, 'option_ids' => $pick($rice, ['Not spicy'])],
            ['menu_item_id' => $latte->id, 'quantity' => 1, 'option_ids' => $pick($latte, ['Small', 'Normal sugar', 'Normal ice'])],
        ], idempotencyKey: (string) Str::uuid());
    }

    private function openBill(): Bill
    {
        $order = $this->placeOrder();

        return $this->bills->openFor($order->session, $this->cashier);
    }

    private function pay(Bill $bill, array $input): Payment
    {
        return $this->bills->addPayment($bill, $input + ['idempotency_key' => (string) Str::uuid()], $this->cashier);
    }

    private function assertRejected(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail("Expected a validation error on {$field}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_opening_a_bill_snapshots_rates_and_is_idempotent(): void
    {
        $bill = $this->openBill();

        $this->assertSame(1, $bill->number);
        $this->assertSame(525, $bill->subtotal);
        $this->assertSame(525, $bill->total);
        $this->assertSame(21500, $bill->total_khr);
        $this->assertSame(4100, $bill->khr_per_usd);
        $this->assertSame(BillStatus::Open, $bill->status);

        // The rate changing later does not touch this bill; opening again returns the same one.
        $this->company->update(['khr_per_usd' => 4000]);
        $again = $this->bills->openFor($bill->session, $this->cashier);

        $this->assertSame($bill->id, $again->id);
        $this->assertSame(4100, $again->khr_per_usd);
        $this->assertSame(1, Bill::query()->count());
    }

    public function test_bill_follows_new_and_cancelled_orders_while_open(): void
    {
        $bill = $this->openBill();
        $second = $this->placeOrder();
        $this->assertSame($bill->table_session_id, $second->table_session_id);

        $this->assertSame(1050, $this->bills->refresh($bill)->total);

        $second->moveTo(OrderStatus::Cancelled, $this->cashier, 'Customer changed mind');
        $this->assertSame(525, $this->bills->refresh($bill)->total);
    }

    public function test_vat_exclusive_with_service_charge(): void
    {
        $this->company->update(['vat_bp' => 1000, 'service_charge_bp' => 500, 'prices_include_vat' => false]);

        $bill = $this->openBill();

        $this->assertSame(26, $bill->service_charge); // 5% of 5.25 = 0.2625 → 0.26
        $this->assertSame(55, $bill->vat);            // 10% of 5.51 = 0.551 → 0.55
        $this->assertSame(606, $bill->total);
        $this->assertSame(24800, $bill->total_khr);  // 606 × 41 = 24,846 → 24,800
    }

    public function test_vat_inclusive_adds_nothing(): void
    {
        $this->company->update(['vat_bp' => 1000, 'prices_include_vat' => true]);

        $bill = $this->openBill();

        $this->assertSame(0, $bill->vat);
        $this->assertSame(525, $bill->total);
    }

    public function test_discount_needs_manager_pin_and_reason(): void
    {
        $bill = $this->openBill();

        $this->assertRejected(fn () => $this->bills->addDiscount($bill, 'percent', 1000, 'Regular', '9999', $this->cashier), 'pin');
        $this->assertRejected(fn () => $this->bills->addDiscount($bill, 'percent', 1000, 'Regular', null, $this->cashier), 'pin');
        $this->assertRejected(fn () => $this->bills->addDiscount($bill, 'percent', 1000, '', '1234', $this->cashier), 'reason');
        $this->assertRejected(fn () => $this->bills->addDiscount($bill, 'percent', 10001, 'Too much', '1234', $this->cashier), 'value');

        $discount = $this->bills->addDiscount($bill, 'percent', 1000, 'Regular customer', '1234', $this->cashier);

        $this->assertSame(53, $discount->amount); // 10% of 5.25 = 0.525 → 0.53
        $bill->refresh();
        $this->assertSame(53, $bill->discount_total);
        $this->assertSame(472, $bill->total);

        $owner = User::query()->where('email', 'owner@roumdoul.test')->value('id');
        $this->assertSame($owner, $discount->approved_by_user_id);
        $this->assertTrue(AuditLog::query()->where('event', 'bill.discount_added')->where('reason', 'Regular customer')->exists());

        $this->bills->removeDiscount($discount, '1234', $this->cashier);
        $this->assertSame(525, $bill->refresh()->total);
        $this->assertNotNull($discount->refresh()->removed_at);
    }

    public function test_wrong_pins_are_rate_limited(): void
    {
        $bill = $this->openBill();

        foreach (range(1, 5) as $i) {
            $this->assertRejected(fn () => $this->bills->addDiscount($bill, 'fixed', 50, 'x', '0000', $this->cashier), 'pin');
        }

        try {
            $this->bills->addDiscount($bill, 'fixed', 50, 'x', '1234', $this->cashier);
            $this->fail('A correct PIN after five wrong ones should still wait.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Too many', $e->errors()['pin'][0]);
        }
    }

    public function test_split_cash_and_khqr_closes_the_bill_and_the_visit(): void
    {
        $bill = $this->openBill();
        $session = $bill->session;
        $order = Order::query()->where('table_session_id', $session->id)->firstOrFail();
        $order->moveTo(OrderStatus::Preparing);
        $order->moveTo(OrderStatus::Ready);
        $order->moveTo(OrderStatus::Served);

        $cash = $this->pay($bill, ['method' => 'cash', 'tendered_amount' => 300, 'tendered_currency' => 'USD']);
        $this->assertSame(300, $cash->amount);
        $this->assertSame(0, $cash->change_amount);
        $this->assertSame(BillStatus::Open, $bill->refresh()->status);
        $this->assertSame(225, $bill->remaining());

        $this->assertRejected(fn () => $this->pay($bill, ['method' => 'khqr', 'amount' => 226]), 'amount');

        $khqr = $this->pay($bill, ['method' => 'khqr', 'reference' => 'ABA-123456']);
        $this->assertSame(225, $khqr->amount);

        $bill->refresh();
        $this->assertSame(BillStatus::Paid, $bill->status);
        $this->assertSame(525, $bill->paid_total);
        $this->assertNotNull($bill->paid_at);
        $this->assertSame('closed', $session->refresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);

        // Paid bills accept nothing more, and the next order starts a new visit.
        $this->assertRejected(fn () => $this->pay($bill, ['method' => 'khqr', 'amount' => 100]), 'bill');
        $next = $this->placeOrder();
        $this->assertNotSame($session->id, $next->table_session_id);
    }

    public function test_order_served_after_paying_is_completed(): void
    {
        $bill = $this->openBill();
        $this->pay($bill, ['method' => 'cash', 'tendered_amount' => 525, 'tendered_currency' => 'USD']);

        $order = Order::query()->where('table_session_id', $bill->table_session_id)->firstOrFail();
        $this->assertSame(OrderStatus::Placed, $order->status);

        $order->moveTo(OrderStatus::Preparing);
        $order->moveTo(OrderStatus::Ready);
        $order->moveTo(OrderStatus::Served);

        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
    }

    public function test_cash_over_the_total_gives_change(): void
    {
        $bill = $this->openBill();

        // $10 for $5.25: $4.75 back
        $payment = $this->pay($bill, ['method' => 'cash', 'tendered_amount' => 1000, 'tendered_currency' => 'USD']);
        $this->assertSame(525, $payment->amount);
        $this->assertSame(475, $payment->change_amount);
        $this->assertSame('USD', $payment->change_currency);
        $this->assertSame(BillStatus::Paid, $bill->refresh()->status);
    }

    public function test_cash_in_riel_settles_at_the_rounded_riel_total_and_change_can_be_riel(): void
    {
        $bill = $this->openBill(); // $5.25 = 21,525៛ → ask 21,500៛

        $payment = $this->pay($bill, ['method' => 'cash', 'tendered_amount' => 21500, 'tendered_currency' => 'KHR']);
        $this->assertSame(525, $payment->amount);
        $this->assertSame(0, $payment->change_amount);
        $this->assertSame(BillStatus::Paid, $bill->refresh()->status);

        // Dollars handed over, change in riel: $10 for $5.25 → $4.75 = 19,475៛ → 19,500៛
        $other = $this->company->diningTables()->where('name', 'T2')->firstOrFail();
        $bill2 = $this->bills->openFor($this->placeOrder($other)->session, $this->cashier);
        $payment2 = $this->pay($bill2, ['method' => 'cash', 'tendered_amount' => 1000, 'tendered_currency' => 'USD', 'change_currency' => 'KHR']);
        $this->assertSame(19500, $payment2->change_amount);
        $this->assertSame('KHR', $payment2->change_currency);
        $this->assertSame(2, $bill2->number);
    }

    public function test_partial_riel_payment_credits_only_what_was_given(): void
    {
        $bill = $this->openBill();

        $payment = $this->pay($bill, ['method' => 'cash', 'tendered_amount' => 10000, 'tendered_currency' => 'KHR']);
        $this->assertSame(243, $payment->amount); // 10,000 / 4,100 = 2.439… → $2.43
        $this->assertSame(282, $bill->refresh()->remaining());
    }

    public function test_same_payment_key_twice_records_one_payment(): void
    {
        $bill = $this->openBill();
        $input = ['idempotency_key' => 'pay-key-0001', 'method' => 'khqr', 'amount' => 200];

        $first = $this->bills->addPayment($bill, $input, $this->cashier);
        $second = $this->bills->addPayment($bill, $input, $this->cashier);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(200, $bill->refresh()->paid_total);
    }

    public function test_khqr_reference_cannot_be_used_twice(): void
    {
        $bill = $this->openBill();

        $this->pay($bill, ['method' => 'khqr', 'amount' => 100, 'reference' => 'ABA-777']);
        $this->assertRejected(fn () => $this->pay($bill, ['method' => 'khqr', 'amount' => 100, 'reference' => 'ABA-777']), 'reference');
    }

    public function test_refund_needs_pin_and_makes_the_amount_due_again(): void
    {
        $bill = $this->openBill();
        $payment = $this->pay($bill, ['method' => 'khqr', 'amount' => 200]);

        $this->assertRejected(fn () => $this->bills->refund($payment, 'Wrong table', '0000', $this->cashier), 'pin');
        $this->assertRejected(fn () => $this->bills->refund($payment, '', '1234', $this->cashier), 'reason');

        $this->bills->refund($payment, 'Wrong table', '1234', $this->cashier);

        $this->assertSame('refunded', $payment->refresh()->status);
        $this->assertSame(0, $bill->refresh()->paid_total);
        $this->assertSame(525, $bill->remaining());
        $this->assertTrue(AuditLog::query()->where('event', 'payment.refunded')->where('reason', 'Wrong table')->exists());
        $this->assertRejected(fn () => $this->bills->refund($payment, 'Again', '1234', $this->cashier), 'payment');
    }

    public function test_void_needs_pin_and_no_payments_and_frees_the_table(): void
    {
        $bill = $this->openBill();
        $payment = $this->pay($bill, ['method' => 'khqr', 'amount' => 100]);

        $this->assertRejected(fn () => $this->bills->void($bill, 'Walked out', '0000', $this->cashier), 'pin');
        $this->assertRejected(fn () => $this->bills->void($bill, 'Walked out', '1234', $this->cashier), 'bill');

        $this->bills->refund($payment, 'Mistake', '1234', $this->cashier);
        $this->bills->void($bill, 'Walked out', '1234', $this->cashier);

        $bill->refresh();
        $this->assertSame(BillStatus::Void, $bill->status);
        $this->assertSame('Walked out', $bill->void_reason);
        $this->assertSame('closed', TableSession::query()->find($bill->table_session_id)->status);
        $this->assertTrue(AuditLog::query()->where('event', 'bill.voided')->where('reason', 'Walked out')->exists());
    }

    public function test_paying_clears_the_bill_request(): void
    {
        $order = $this->placeOrder();
        $this->postJson("/api/public/tables/{$this->table->qr_token}/requests", ['type' => 'bill'])->assertCreated();
        $this->assertSame('bill_requested', $order->session->refresh()->status);

        $bill = $this->bills->openFor($order->session, $this->cashier);
        $this->pay($bill, ['method' => 'cash', 'tendered_amount' => 525, 'tendered_currency' => 'USD']);

        $this->assertSame(0, $this->table->branch->serviceRequests()->where('status', 'open')->count());
    }

    public function test_order_retry_key_from_another_table_is_refused(): void
    {
        $order = $this->placeOrder();
        $other = $this->company->diningTables()->where('name', 'T2')->firstOrFail();

        $this->assertRejected(fn () => OrderPlacer::existing($other, $order->idempotency_key), 'idempotency_key');
        $this->assertSame($order->id, OrderPlacer::existing($this->table, $order->idempotency_key)->id);
    }
}
