<?php

namespace App\Services\Billing;

use App\Enums\OrderStatus;
use App\Models\Bill;
use App\Models\BillAdjustment;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Ordering\OrderPresenter;
use App\Support\Money;

/** JSON shapes for the cashier screen. */
class BillPresenter
{
    /** @return array<string, mixed> */
    public static function bill(Bill $bill): array
    {
        $bill->loadMissing(['session.table.area', 'activeAdjustments.approvedBy', 'payments.receivedBy']);

        $orders = Order::query()
            ->where('table_session_id', $bill->table_session_id)
            ->with(['items', 'table.area'])
            ->orderBy('id')
            ->get();

        return [
            'id' => $bill->id,
            'number' => $bill->number,
            'status' => $bill->status->value,
            'table' => $bill->session?->table?->name,
            'area' => $bill->session?->table?->area?->name,
            'session_id' => $bill->table_session_id,
            'currency' => $bill->currency,
            'khr_per_usd' => $bill->khr_per_usd,
            'subtotal' => $bill->subtotal,
            'discount_total' => $bill->discount_total,
            'service_charge' => $bill->service_charge,
            'service_charge_bp' => $bill->service_charge_bp,
            'vat' => $bill->vat,
            'vat_bp' => $bill->vat_bp,
            'prices_include_vat' => $bill->prices_include_vat,
            'vat_included' => $bill->prices_include_vat ? BillCalculator::includedVat($bill->total, $bill->vat_bp) : $bill->vat,
            'total' => $bill->total,
            'total_khr' => $bill->total_khr,
            'paid_total' => $bill->paid_total,
            'remaining' => $bill->remaining(),
            'remaining_khr' => Money::toRiel($bill->remaining(), $bill->currency, $bill->khr_per_usd),
            'opened_at' => $bill->created_at?->toIso8601String(),
            'paid_at' => $bill->paid_at?->toIso8601String(),
            'void_reason' => $bill->void_reason,
            'orders' => $orders->map(fn (Order $o) => OrderPresenter::order($o, forStaff: true) + [
                'counts' => $o->status !== OrderStatus::Cancelled,
            ])->values()->all(),
            'adjustments' => $bill->activeAdjustments->map(fn (BillAdjustment $a) => [
                'id' => $a->id,
                'type' => $a->type,
                'value' => $a->value,
                'amount' => $a->amount,
                'reason' => $a->reason,
                'approved_by' => $a->approvedBy?->name,
            ])->values()->all(),
            'payments' => $bill->payments->map(fn (Payment $p) => self::payment($p))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function payment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'method' => $payment->method->value,
            'amount' => $payment->amount,
            'tendered_amount' => $payment->tendered_amount,
            'tendered_currency' => $payment->tendered_currency,
            'change_amount' => $payment->change_amount,
            'change_currency' => $payment->change_currency,
            'reference' => $payment->reference,
            'status' => $payment->status,
            'received_by' => $payment->receivedBy?->name,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'refund_reason' => $payment->refund_reason,
        ];
    }
}
