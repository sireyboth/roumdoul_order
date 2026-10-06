<?php

namespace App\Services\Billing;

use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The cash drawer. A shift opens with the cash counted in the drawer (dollars
 * and riel separately), every payment belongs to the open shift, and closing
 * compares what should be in the drawer with what was counted.
 *
 * Expected cash, per currency =
 *   opening + cash handed over − change given (payments taken in this shift)
 *   + cash in − cash out (movements)
 *   − cash refunded in this shift (handed back, net of the change given then)
 */
class ShiftService
{
    public static function openFor(int $branchId): ?Shift
    {
        return Shift::query()->where('branch_id', $branchId)->where('status', 'open')->latest('id')->first();
    }

    public function open(Branch $branch, User $by, int $openingUsd, int $openingKhr, ?string $note = null): Shift
    {
        if ($openingUsd < 0 || $openingKhr < 0) {
            throw ValidationException::withMessages(['opening_cash_usd' => 'Cash in the drawer cannot be negative.']);
        }

        return DB::transaction(function () use ($branch, $by, $openingUsd, $openingKhr, $note) {
            Branch::query()->whereKey($branch->id)->lockForUpdate()->first();

            if (self::openFor($branch->id)) {
                throw ValidationException::withMessages(['shift' => 'A shift is already open at this branch.']);
            }

            $shift = Shift::query()->create([
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'status' => 'open',
                'opened_by_user_id' => $by->id,
                'opened_at' => now(),
                'opening_cash_usd' => $openingUsd,
                'opening_cash_khr' => $openingKhr,
                'note' => filled($note) ? mb_substr(trim($note), 0, 255) : null,
            ]);

            AuditLogger::record('shift.opened', $shift, [
                'opening_cash_usd' => $openingUsd,
                'opening_cash_khr' => $openingKhr,
            ], companyId: $shift->company_id);

            return $shift;
        });
    }

    public function addMovement(Shift $shift, string $type, int $amount, string $currency, string $reason, User $by): CashMovement
    {
        if (! in_array($type, ['in', 'out'], true) || ! in_array($currency, ['USD', 'KHR'], true)) {
            throw ValidationException::withMessages(['type' => 'Choose cash in or out, in dollars or riel.']);
        }

        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount.']);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Please say what the cash is for.']);
        }

        return DB::transaction(function () use ($shift, $type, $amount, $currency, $reason, $by) {
            $shift = $this->lockOpen($shift);

            $movement = $shift->movements()->create([
                'company_id' => $shift->company_id,
                'type' => $type,
                'amount' => $amount,
                'currency' => $currency,
                'reason' => mb_substr(trim($reason), 0, 255),
                'user_id' => $by->id,
            ]);

            AuditLogger::record('shift.cash_'.$type, $shift, [
                'amount' => $amount,
                'currency' => $currency,
            ], reason: $reason, companyId: $shift->company_id);

            return $movement;
        });
    }

    public function close(Shift $shift, int $countedUsd, int $countedKhr, ?string $note, User $by): Shift
    {
        if ($countedUsd < 0 || $countedKhr < 0) {
            throw ValidationException::withMessages(['counted_cash_usd' => 'Counted cash cannot be negative.']);
        }

        return DB::transaction(function () use ($shift, $countedUsd, $countedKhr, $note, $by) {
            $shift = $this->lockOpen($shift);
            $summary = self::summary($shift);

            $shift->update([
                'status' => 'closed',
                'closed_by_user_id' => $by->id,
                'closed_at' => now(),
                'expected_cash_usd' => $summary['expected_usd'],
                'expected_cash_khr' => $summary['expected_khr'],
                'counted_cash_usd' => $countedUsd,
                'counted_cash_khr' => $countedKhr,
                'difference_usd' => $countedUsd - $summary['expected_usd'],
                'difference_khr' => $countedKhr - $summary['expected_khr'],
                'note' => filled($note) ? mb_substr(trim($note), 0, 255) : $shift->note,
            ]);

            AuditLogger::record('shift.closed', $shift, [
                'expected_usd' => $shift->expected_cash_usd,
                'expected_khr' => $shift->expected_cash_khr,
                'counted_usd' => $countedUsd,
                'counted_khr' => $countedKhr,
                'difference_usd' => $shift->difference_usd,
                'difference_khr' => $shift->difference_khr,
            ], reason: $note, companyId: $shift->company_id);

            return $shift;
        });
    }

    /**
     * Everything the close-out screen shows. Amounts per method are in the
     * bill currency; drawer amounts are cents (USD) and riel (KHR).
     *
     * @return array<string, mixed>
     */
    public static function summary(Shift $shift): array
    {
        $drawer = ['USD' => 0, 'KHR' => 0];
        $received = ['USD' => 0, 'KHR' => 0];
        $change = ['USD' => 0, 'KHR' => 0];
        $refunded = ['USD' => 0, 'KHR' => 0];

        $cash = Payment::query()->where('shift_id', $shift->id)->where('method', PaymentMethod::Cash->value)->get();
        foreach ($cash as $payment) {
            $received[$payment->tendered_currency] += (int) $payment->tendered_amount;
            if ($payment->change_amount > 0) {
                $change[$payment->change_currency] += $payment->change_amount;
            }
        }

        $cashRefunds = Payment::query()->where('refunded_in_shift_id', $shift->id)->where('method', PaymentMethod::Cash->value)->get();
        foreach ($cashRefunds as $payment) {
            $refunded[$payment->tendered_currency] += (int) $payment->tendered_amount;
            if ($payment->change_amount > 0) {
                $refunded[$payment->change_currency] -= $payment->change_amount;
            }
        }

        $movements = CashMovement::query()->where('shift_id', $shift->id)->get();
        $in = ['USD' => 0, 'KHR' => 0];
        $out = ['USD' => 0, 'KHR' => 0];
        foreach ($movements as $movement) {
            if ($movement->type === 'in') {
                $in[$movement->currency] += $movement->amount;
            } else {
                $out[$movement->currency] += $movement->amount;
            }
        }

        foreach (['USD', 'KHR'] as $c) {
            $opening = $c === 'USD' ? $shift->opening_cash_usd : $shift->opening_cash_khr;
            $drawer[$c] = $opening + $received[$c] - $change[$c] + $in[$c] - $out[$c] - $refunded[$c];
        }

        $byMethod = Payment::query()
            ->where('shift_id', $shift->id)
            ->where('status', 'confirmed')
            ->selectRaw('method, count(*) as count, sum(amount) as amount')
            ->groupBy('method')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->method instanceof PaymentMethod ? $row->method->value : $row->method => [
                'count' => (int) $row->count,
                'amount' => (int) $row->amount,
            ]]);

        return [
            'expected_usd' => $drawer['USD'],
            'expected_khr' => $drawer['KHR'],
            'opening_usd' => $shift->opening_cash_usd,
            'opening_khr' => $shift->opening_cash_khr,
            'cash_received_usd' => $received['USD'],
            'cash_received_khr' => $received['KHR'],
            'change_given_usd' => $change['USD'],
            'change_given_khr' => $change['KHR'],
            'cash_in_usd' => $in['USD'],
            'cash_in_khr' => $in['KHR'],
            'cash_out_usd' => $out['USD'],
            'cash_out_khr' => $out['KHR'],
            'refunded_usd' => $refunded['USD'],
            'refunded_khr' => $refunded['KHR'],
            'by_method' => $byMethod->all(),
            'refunds_count' => Payment::query()->where('refunded_in_shift_id', $shift->id)->count(),
        ];
    }

    private function lockOpen(Shift $shift): Shift
    {
        $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);

        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => 'This shift is already closed.']);
        }

        return $shift;
    }
}
