<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Billing\BillService;
use App\Services\Billing\ShiftService;
use App\Services\Ordering\OrderPlacer;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShiftTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $cashier;

    private ShiftService $shifts;

    private BillService $bills;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $company = Company::query()->where('slug', 'demo-cafe')->firstOrFail();
        $this->branch = $company->branches()->firstOrFail();
        $this->cashier = User::query()->where('email', 'cashier@roumdoul.test')->firstOrFail();
        $this->shifts = app(ShiftService::class);
        $this->bills = app(BillService::class);
    }

    /** A $5.25 bill on the given table. */
    private function bill(string $tableName): Bill
    {
        $table = DiningTable::query()->where('branch_id', $this->branch->id)->where('name', $tableName)->firstOrFail();
        $rice = MenuItem::query()->where('name_en', 'Fried rice')->with('optionGroups.options')->firstOrFail();
        $latte = MenuItem::query()->where('name_en', 'Iced latte')->with('optionGroups.options')->firstOrFail();
        $pick = fn (MenuItem $i, array $n) => $i->optionGroups->flatMap->options->whereIn('name_en', $n)->pluck('id')->all();

        $order = app(OrderPlacer::class)->place($table, [
            ['menu_item_id' => $rice->id, 'quantity' => 1, 'option_ids' => $pick($rice, ['Not spicy'])],
            ['menu_item_id' => $latte->id, 'quantity' => 1, 'option_ids' => $pick($latte, ['Small', 'Normal sugar', 'Normal ice'])],
        ], idempotencyKey: (string) Str::uuid());

        return $this->bills->openFor($order->session, $this->cashier);
    }

    private function pay(Bill $bill, array $input): Payment
    {
        return $this->bills->addPayment($bill, $input + ['idempotency_key' => (string) Str::uuid()], $this->cashier);
    }

    public function test_payments_need_an_open_shift(): void
    {
        $bill = $this->bill('T1');

        try {
            $this->pay($bill, ['method' => 'khqr']);
            $this->fail('Payment without a shift should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shift', $e->errors());
        }

        $shift = $this->shifts->open($this->branch, $this->cashier, 0, 0);
        $payment = $this->pay($bill, ['method' => 'khqr']);

        $this->assertSame($shift->id, $payment->shift_id);
    }

    public function test_only_one_open_shift_per_branch(): void
    {
        $this->shifts->open($this->branch, $this->cashier, 0, 0);

        $this->expectException(ValidationException::class);
        $this->shifts->open($this->branch, $this->cashier, 0, 0);
    }

    public function test_drawer_count_in_dollars_and_riel(): void
    {
        // Opening float: $20 and 50,000៛
        $shift = $this->shifts->open($this->branch, $this->cashier, 2000, 50000);

        // T1: $10 note for $5.25 → $4.75 change
        $usdCash = $this->pay($this->bill('T1'), ['method' => 'cash', 'tendered_amount' => 1000, 'tendered_currency' => 'USD']);
        // T2: exactly 21,500៛
        $this->pay($this->bill('T2'), ['method' => 'cash', 'tendered_amount' => 21500, 'tendered_currency' => 'KHR']);
        // T3: KHQR never touches the drawer
        $this->pay($this->bill('T3'), ['method' => 'khqr', 'reference' => 'ABA-9']);

        $this->shifts->addMovement($shift, 'in', 10000, 'KHR', 'More small notes', $this->cashier);
        $this->shifts->addMovement($shift, 'out', 200, 'USD', 'Ice delivery', $this->cashier);

        // The $10 sale is refunded: $5.25 goes back out of the drawer.
        $this->bills->refund($usdCash, 'Wrong table', '1234', $this->cashier);

        $summary = ShiftService::summary($shift->fresh());
        $this->assertSame(2000 + 1000 - 475 - 200 - 525, $summary['expected_usd']); // 1,800 = $18.00
        $this->assertSame(50000 + 21500 + 10000, $summary['expected_khr']);        // 81,500៛
        $this->assertSame(['count' => 1, 'amount' => 525], $summary['by_method']['khqr']);
        $this->assertSame(['count' => 1, 'amount' => 525], $summary['by_method']['cash']); // the refunded one is not counted
        $this->assertSame(1, $summary['refunds_count']);

        $closed = $this->shifts->close($shift, 1800, 81000, 'Short 500៛', $this->cashier);

        $this->assertSame('closed', $closed->status);
        $this->assertSame(1800, $closed->expected_cash_usd);
        $this->assertSame(0, $closed->difference_usd);
        $this->assertSame(-500, $closed->difference_khr);
        $this->assertTrue(AuditLog::query()->where('event', 'shift.closed')->exists());

        // Closed shifts take nothing more.
        $this->expectException(ValidationException::class);
        $this->shifts->addMovement($closed, 'in', 100, 'USD', 'Late', $this->cashier);
    }

    public function test_cash_refund_comes_out_of_the_shift_open_at_the_time(): void
    {
        $first = $this->shifts->open($this->branch, $this->cashier, 0, 0);
        $payment = $this->pay($this->bill('T1'), ['method' => 'cash', 'tendered_amount' => 525, 'tendered_currency' => 'USD']);
        $this->shifts->close($first, 525, 0, null, $this->cashier);

        // No shift open: cash cannot be handed back.
        try {
            $this->bills->refund($payment, 'Cold food', '1234', $this->cashier);
            $this->fail('Cash refund without a shift should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shift', $e->errors());
        }

        $second = $this->shifts->open($this->branch, $this->cashier, 525, 0);
        $this->bills->refund($payment, 'Cold food', '1234', $this->cashier);

        $this->assertSame($second->id, $payment->fresh()->refunded_in_shift_id);
        $this->assertSame(525, ShiftService::summary($first->fresh())['expected_usd']);
        $this->assertSame(0, ShiftService::summary($second->fresh())['expected_usd']);
    }

    public function test_shift_api_for_the_cashier_screen(): void
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/staff/login', ['email' => 'cashier@roumdoul.test', 'password' => 'password'])->json('data.token');

        $this->withToken($token)->getJson("/api/staff/branches/{$this->branch->id}/shift")
            ->assertOk()->assertJsonPath('data.shift', null);

        $shiftId = $this->withToken($token)->postJson("/api/staff/branches/{$this->branch->id}/shift", [
            'opening_cash_usd' => 2000, 'opening_cash_khr' => 40000,
        ])->assertCreated()->assertJsonPath('data.summary.expected_usd', 2000)->json('data.id');

        $this->withToken($token)->postJson("/api/staff/branches/{$this->branch->id}/shift", [
            'opening_cash_usd' => 0, 'opening_cash_khr' => 0,
        ])->assertStatus(422);

        $this->withToken($token)->postJson("/api/staff/shifts/{$shiftId}/movements", [
            'type' => 'out', 'amount' => 500, 'currency' => 'USD', 'reason' => 'Gas',
        ])->assertCreated()->assertJsonPath('data.summary.expected_usd', 1500)->assertJsonPath('data.movements.0.reason', 'Gas');

        $this->withToken($token)->postJson("/api/staff/shifts/{$shiftId}/close", [
            'counted_cash_usd' => 1500, 'counted_cash_khr' => 41000,
        ])->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.difference_khr', 1000);

        $this->withToken($token)->getJson("/api/staff/branches/{$this->branch->id}/shift")
            ->assertJsonPath('data.shift', null)
            ->assertJsonPath('data.last_closed.id', $shiftId);

        // Waiters do not handle the drawer.
        $this->app['auth']->forgetGuards();
        $waiter = $this->postJson('/api/staff/login', ['email' => 'waiter@roumdoul.test', 'password' => 'password'])->json('data.token');
        $this->withToken($waiter)->getJson("/api/staff/branches/{$this->branch->id}/shift")->assertForbidden();

        $this->assertSame(1, Shift::query()->count());
    }
}
