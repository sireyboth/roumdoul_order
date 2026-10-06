<?php

namespace App\Services\Billing;

use App\Enums\BillStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Bill;
use App\Models\BillAdjustment;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ServiceRequest;
use App\Models\TableSession;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ManagerPin;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a bill or payment goes through here.
 *
 * Locks are always taken in the order branch → table → bill, the same order
 * OrderPlacer uses, so a payment and a new order for the same table can never
 * deadlock, and a new order can never slip into a visit that is being closed.
 */
class BillService
{
    /** One bill per visit: calling this again returns the same bill, with fresh totals. */
    public function openFor(TableSession $session, ?User $by = null): Bill
    {
        if ($existing = Bill::query()->where('table_session_id', $session->id)->first()) {
            return $existing->isOpen() ? $this->refresh($existing) : $existing;
        }

        try {
            return DB::transaction(function () use ($session, $by) {
                $branch = Branch::query()->with('company')->lockForUpdate()->findOrFail($session->branch_id);
                DiningTable::query()->whereKey($session->dining_table_id)->lockForUpdate()->first();
                $session = TableSession::query()->lockForUpdate()->findOrFail($session->id);

                if ($existing = Bill::query()->where('table_session_id', $session->id)->first()) {
                    return $existing;
                }

                if ($session->status === 'closed') {
                    throw ValidationException::withMessages(['session' => 'This table visit is already closed.']);
                }

                $company = $branch->company;
                $businessDate = $branch->businessDate();

                $bill = Bill::query()->create([
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'table_session_id' => $session->id,
                    'number' => (int) Bill::query()
                        ->where('branch_id', $branch->id)
                        ->whereDate('business_date', $businessDate)
                        ->max('number') + 1,
                    'business_date' => $businessDate,
                    'currency' => $company->currency,
                    'khr_per_usd' => $company->khr_per_usd,
                    'service_charge_bp' => $company->service_charge_bp,
                    'vat_bp' => $company->vat_bp,
                    'prices_include_vat' => $company->prices_include_vat,
                    'status' => BillStatus::Open,
                    'opened_by_user_id' => $by?->id,
                ]);

                $this->recalculate($bill);

                AuditLogger::record('bill.opened', $bill, [
                    'number' => $bill->number,
                    'total' => $bill->total,
                ], companyId: $bill->company_id);

                return $bill;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two screens opened the same table's bill at the same moment; the other one won.
            return Bill::query()->where('table_session_id', $session->id)->firstOrFail();
        }
    }

    /** Re-reads the orders and discounts of an open bill and saves the new totals. */
    public function refresh(Bill $bill): Bill
    {
        return DB::transaction(function () use ($bill) {
            $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);

            if ($bill->isOpen()) {
                $this->recalculate($bill);
            }

            return $bill;
        });
    }

    /** Totals as they would be now, without saving anything (for screens that only look). */
    public static function preview(TableSession $session): BillTotals
    {
        $session->loadMissing('branch.company');
        $company = $session->branch->company;
        $bill = Bill::query()->where('table_session_id', $session->id)->first();

        $adjustments = $bill
            ? $bill->activeAdjustments()->get(['type', 'value'])->map(fn ($a) => ['type' => $a->type, 'value' => $a->value])->all()
            : [];

        return BillCalculator::calculate(
            self::ordersSubtotal($session->id),
            $adjustments,
            $bill?->service_charge_bp ?? $company->service_charge_bp,
            $bill?->vat_bp ?? $company->vat_bp,
            $bill?->prices_include_vat ?? $company->prices_include_vat,
            $bill?->currency ?? $company->currency,
            $bill?->khr_per_usd ?? $company->khr_per_usd,
        );
    }

    public function addDiscount(Bill $bill, string $type, int $value, string $reason, ?string $pin, User $by): BillAdjustment
    {
        if (! in_array($type, BillAdjustment::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Choose a percent or a fixed amount.']);
        }

        if ($value < 1 || ($type === 'percent' && $value > 10000)) {
            throw ValidationException::withMessages(['value' => 'Enter a discount between 0 and 100%.']);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Please give a reason for the discount.']);
        }

        $approver = ManagerPin::approve($bill->company_id, $pin, $by);

        return DB::transaction(function () use ($bill, $type, $value, $reason, $by, $approver) {
            $bill = $this->lockOpenBill($bill);

            $adjustment = $bill->adjustments()->create([
                'company_id' => $bill->company_id,
                'type' => $type,
                'value' => $value,
                'reason' => mb_substr(trim($reason), 0, 255),
                'created_by_user_id' => $by->id,
                'approved_by_user_id' => $approver->id,
            ]);

            $this->recalculate($bill);

            AuditLogger::record('bill.discount_added', $bill, [
                'type' => $type,
                'value' => $value,
                'amount' => $adjustment->fresh()->amount,
                'approved_by' => $approver->id,
                'total' => $bill->total,
            ], reason: $reason, companyId: $bill->company_id);

            return $adjustment->fresh();
        });
    }

    public function removeDiscount(BillAdjustment $adjustment, ?string $pin, User $by): Bill
    {
        $approver = ManagerPin::approve($adjustment->company_id, $pin, $by);

        return DB::transaction(function () use ($adjustment, $by, $approver) {
            $bill = $this->lockOpenBill($adjustment->bill);
            $adjustment = BillAdjustment::query()->whereKey($adjustment->id)->whereNull('removed_at')->firstOrFail();

            $adjustment->update(['removed_at' => now(), 'removed_by_user_id' => $by->id]);
            $this->recalculate($bill);

            AuditLogger::record('bill.discount_removed', $bill, [
                'adjustment_id' => $adjustment->id,
                'approved_by' => $approver->id,
                'total' => $bill->total,
            ], companyId: $bill->company_id);

            return $bill;
        });
    }

    /**
     * Records money received.
     *
     * Cash: pass `tendered_amount` + `tendered_currency` (USD cents or whole riel).
     * Anything over what is due becomes change, in `change_currency` (default: the
     * tendered currency). Riel is compared with the due amount rounded to 100៛, so
     * handing over exactly the riel total settles the bill.
     *
     * KHQR / card / other: pass `amount` in the bill currency (default: everything
     * still due). These can never pay more than is due.
     *
     * The same `idempotency_key` sent twice returns the first payment.
     *
     * @param  array{idempotency_key:string, method:string, amount?:int|null, tendered_amount?:int|null,
     *     tendered_currency?:string|null, change_currency?:string|null, reference?:string|null, shift_id?:int|null}  $input
     */
    public function addPayment(Bill $bill, array $input, User $by): Payment
    {
        $key = (string) ($input['idempotency_key'] ?? '');
        $method = PaymentMethod::tryFrom((string) ($input['method'] ?? ''))
            ?? throw ValidationException::withMessages(['method' => 'Choose how the customer paid.']);

        if ($key === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'Missing payment key.']);
        }

        if ($existing = $this->existingPayment($bill, $key)) {
            return $existing;
        }

        $reference = filled($input['reference'] ?? null) ? trim((string) $input['reference']) : null;

        try {
            return DB::transaction(function () use ($bill, $input, $by, $key, $method, $reference) {
                DiningTable::query()->whereKey($bill->session()->value('dining_table_id'))->lockForUpdate()->first();
                $bill = $this->lockOpenBill($bill);
                $this->recalculate($bill);

                $due = $bill->remaining();

                if ($due <= 0) {
                    throw ValidationException::withMessages(['amount' => 'This bill has nothing left to pay.']);
                }

                if ($reference && Payment::query()->where('branch_id', $bill->branch_id)->where('reference', $reference)->exists()) {
                    throw ValidationException::withMessages(['reference' => 'This KHQR reference was already used for another payment.']);
                }

                $money = $method === PaymentMethod::Cash
                    ? $this->cash($bill, $due, $input)
                    : $this->exact($due, $input);

                $branch = Branch::query()->with('company')->findOrFail($bill->branch_id);

                $payment = Payment::query()->create([
                    'company_id' => $bill->company_id,
                    'branch_id' => $bill->branch_id,
                    'bill_id' => $bill->id,
                    'shift_id' => $input['shift_id'] ?? null,
                    'idempotency_key' => $key,
                    'method' => $method,
                    'amount' => $money['amount'],
                    'tendered_amount' => $money['tendered_amount'],
                    'tendered_currency' => $money['tendered_currency'],
                    'change_amount' => $money['change_amount'],
                    'change_currency' => $money['change_currency'],
                    'khr_per_usd' => $bill->khr_per_usd,
                    'reference' => $reference,
                    'status' => 'confirmed',
                    'received_by_user_id' => $by->id,
                    'paid_at' => now(),
                    'business_date' => $branch->businessDate(),
                ]);

                $bill->update(['paid_total' => $bill->paid_total + $payment->amount]);

                AuditLogger::record('payment.received', $payment, [
                    'bill' => $bill->number,
                    'method' => $method->value,
                    'amount' => $payment->amount,
                    'change' => $payment->change_amount,
                ], companyId: $bill->company_id);

                if ($bill->paid_total >= $bill->total) {
                    $this->settle($bill, $by);
                }

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $this->existingPayment($bill, $key)) {
                return $existing;
            }

            throw ValidationException::withMessages(['reference' => 'This payment was already recorded.']);
        }
    }

    /** Cancels an unpaid bill (customer left, opened by mistake). Payments must be refunded first. */
    public function void(Bill $bill, string $reason, ?string $pin, User $by): Bill
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Please give a reason for voiding the bill.']);
        }

        $approver = ManagerPin::approve($bill->company_id, $pin, $by);

        return DB::transaction(function () use ($bill, $reason, $by, $approver) {
            DiningTable::query()->whereKey($bill->session()->value('dining_table_id'))->lockForUpdate()->first();
            $bill = $this->lockOpenBill($bill);

            if ($bill->payments()->where('status', 'confirmed')->exists()) {
                throw ValidationException::withMessages(['bill' => 'Refund the payments on this bill before voiding it.']);
            }

            $bill->update([
                'status' => BillStatus::Void,
                'voided_at' => now(),
                'void_reason' => mb_substr(trim($reason), 0, 255),
                'voided_by_user_id' => $by->id,
            ]);

            $this->closeSession($bill, $by);

            AuditLogger::record('bill.voided', $bill, [
                'status' => 'void',
                'total' => $bill->total,
                'approved_by' => $approver->id,
            ], ['status' => 'open'], $reason, $bill->company_id);

            return $bill;
        });
    }

    /**
     * Gives a payment back. On an open bill the amount is due again; on a paid
     * bill the sale stays closed and the refund is counted in reports.
     */
    public function refund(Payment $payment, string $reason, ?string $pin, User $by): Payment
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Please give a reason for the refund.']);
        }

        $approver = ManagerPin::approve($payment->company_id, $pin, $by);

        return DB::transaction(function () use ($payment, $reason, $by, $approver) {
            $bill = Bill::query()->lockForUpdate()->findOrFail($payment->bill_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== 'confirmed') {
                throw ValidationException::withMessages(['payment' => 'This payment was already refunded.']);
            }

            $payment->update([
                'status' => 'refunded',
                'refund_reason' => mb_substr(trim($reason), 0, 255),
                'refunded_by_user_id' => $by->id,
                'refunded_at' => now(),
            ]);

            $bill->update(['paid_total' => max(0, $bill->paid_total - $payment->amount)]);

            AuditLogger::record('payment.refunded', $payment, [
                'status' => 'refunded',
                'amount' => $payment->amount,
                'bill' => $bill->number,
                'approved_by' => $approver->id,
            ], ['status' => 'confirmed'], $reason, $payment->company_id);

            return $payment;
        });
    }

    /** Sum of the visit's orders that were not cancelled. */
    public static function ordersSubtotal(int $sessionId): int
    {
        return (int) Order::query()
            ->where('table_session_id', $sessionId)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->sum('subtotal');
    }

    /** Recomputes and saves an open bill's amounts. Call inside a transaction that holds the bill lock. */
    private function recalculate(Bill $bill): void
    {
        $adjustments = $bill->activeAdjustments()->get();

        $totals = BillCalculator::calculate(
            self::ordersSubtotal($bill->table_session_id),
            $adjustments->map(fn (BillAdjustment $a) => ['type' => $a->type, 'value' => $a->value])->all(),
            $bill->service_charge_bp,
            $bill->vat_bp,
            $bill->prices_include_vat,
            $bill->currency,
            $bill->khr_per_usd,
        );

        foreach ($adjustments->values() as $i => $adjustment) {
            if ($adjustment->amount !== $totals->adjustmentAmounts[$i]) {
                $adjustment->update(['amount' => $totals->adjustmentAmounts[$i]]);
            }
        }

        $bill->update([
            'subtotal' => $totals->subtotal,
            'discount_total' => $totals->discountTotal,
            'service_charge' => $totals->serviceCharge,
            'vat' => $totals->vat,
            'total' => $totals->total,
            'total_khr' => $totals->totalKhr,
        ]);
    }

    private function lockOpenBill(Bill $bill): Bill
    {
        $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);

        if (! $bill->isOpen()) {
            throw ValidationException::withMessages(['bill' => "Bill #{$bill->number} is already {$bill->status->value}."]);
        }

        return $bill;
    }

    private function existingPayment(Bill $bill, string $key): ?Payment
    {
        $payment = Payment::query()->where('branch_id', $bill->branch_id)->where('idempotency_key', $key)->first();

        if ($payment && $payment->bill_id !== $bill->id) {
            throw ValidationException::withMessages(['idempotency_key' => 'This payment key belongs to another bill.']);
        }

        return $payment;
    }

    /** @return array{amount:int, tendered_amount:int, tendered_currency:string, change_amount:int, change_currency:string} */
    private function cash(Bill $bill, int $due, array $input): array
    {
        $tendered = (int) ($input['tendered_amount'] ?? 0);
        $tenderedCurrency = strtoupper((string) ($input['tendered_currency'] ?? $bill->currency));
        $changeCurrency = strtoupper((string) ($input['change_currency'] ?? $tenderedCurrency));

        if (! in_array($tenderedCurrency, ['USD', 'KHR'], true) || ! in_array($changeCurrency, ['USD', 'KHR'], true)) {
            throw ValidationException::withMessages(['tendered_currency' => 'Cash must be in dollars or riel.']);
        }

        if ($tendered < 1) {
            throw ValidationException::withMessages(['tendered_amount' => 'Enter the cash the customer handed over.']);
        }

        $rate = $bill->khr_per_usd;

        // What is due, in the currency handed over.
        $dueInTendered = match (true) {
            $tenderedCurrency === 'KHR' => Money::toRiel($due, $bill->currency, $rate),
            $bill->currency === 'USD' => $due,
            default => (int) ceil($due * 100 / $rate), // KHR bill paid in dollars
        };

        if ($tendered >= $dueInTendered) {
            $applied = $due;
            $excess = $tendered - $dueInTendered;
        } else {
            $applied = $tenderedCurrency === 'KHR'
                ? Money::fromRiel($tendered, $bill->currency, $rate)
                : Money::fromUsdCents($tendered, $bill->currency, $rate);
            $excess = 0;
        }

        if ($applied < 1) {
            throw ValidationException::withMessages(['tendered_amount' => 'That is not enough to pay anything.']);
        }

        $change = match (true) {
            $excess === 0 || $changeCurrency === $tenderedCurrency => $excess,
            $changeCurrency === 'KHR' => Money::roundRiel($excess * $rate / 100), // dollars over, change in riel
            default => intdiv($excess * 100, $rate), // riel over, change in dollars
        };

        return [
            'amount' => $applied,
            'tendered_amount' => $tendered,
            'tendered_currency' => $tenderedCurrency,
            'change_amount' => $change,
            'change_currency' => $changeCurrency,
        ];
    }

    /** @return array{amount:int, tendered_amount:null, tendered_currency:null, change_amount:int, change_currency:null} */
    private function exact(int $due, array $input): array
    {
        $amount = isset($input['amount']) && $input['amount'] !== null ? (int) $input['amount'] : $due;

        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount paid.']);
        }

        if ($amount > $due) {
            throw ValidationException::withMessages(['amount' => 'This is more than the bill. Only cash can give change.']);
        }

        return [
            'amount' => $amount,
            'tendered_amount' => null,
            'tendered_currency' => null,
            'change_amount' => 0,
            'change_currency' => null,
        ];
    }

    /** Fully paid: freeze the bill, free the table, finish served orders, clear the "bill please" call. */
    private function settle(Bill $bill, User $by): void
    {
        $bill->update(['status' => BillStatus::Paid, 'paid_at' => now()]);

        $this->closeSession($bill, $by);

        Order::query()
            ->where('table_session_id', $bill->table_session_id)
            ->where('status', OrderStatus::Served->value)
            ->get()
            ->each(fn (Order $order) => $order->moveTo(OrderStatus::Completed, $by));

        AuditLogger::record('bill.paid', $bill, [
            'status' => 'paid',
            'total' => $bill->total,
            'paid_total' => $bill->paid_total,
        ], ['status' => 'open'], companyId: $bill->company_id);
    }

    private function closeSession(Bill $bill, User $by): void
    {
        TableSession::query()->whereKey($bill->table_session_id)->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        ServiceRequest::query()
            ->where('table_session_id', $bill->table_session_id)
            ->where('status', 'open')
            ->update(['status' => 'done', 'handled_by_user_id' => $by->id, 'handled_at' => now()]);
    }
}
