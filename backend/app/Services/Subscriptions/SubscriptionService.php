<?php

namespace App\Services\Subscriptions;

use App\Enums\CompanyStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlanInvoice;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything about a restaurant's plan and what it pays Roumdoul (docs/roadmap.md C2).
 * Used by /admin (Roumdoul team), the owner's "Your plan" page in /app, and the hourly
 * `subscriptions:run` command. Every step is kept: subscriptions are ended, never edited
 * into another plan; invoices are voided with a reason, never deleted. Each step tells the
 * team's Telegram group (PlatformAlerts).
 */
class SubscriptionService
{
    public function __construct(private PlatformAlerts $alerts) {}

    public function quote(Company $company, Plan $plan, string $interval): PlanQuote
    {
        return PlanQuote::for($company->subscription()->with('plan')->first(), $plan, $interval);
    }

    /**
     * Change plan, by the Roumdoul team or the owner. Upgrades make an invoice and start once it
     * is paid ($paid = the team already received the money: confirm at once). Returns that invoice.
     *
     * @param  array{method: string, reference?: string|null}|null  $paid
     */
    public function change(Company $company, Plan $plan, string $interval, ?User $by, ?array $paid = null): ?PlanInvoice
    {
        $current = $company->subscription()->with('plan')->first();
        $quote = PlanQuote::for($current, $plan, $interval);

        switch ($quote->kind) {
            case PlanQuote::NONE:
                return null;

            case PlanQuote::TRIAL:
            case PlanQuote::SWITCH:
                DB::transaction(fn () => $this->replace($company, $current, $plan, $interval, [
                    'status' => $current?->isTrial() ? 'trialing' : 'active',
                    'starts_at' => now(),
                    'ends_at' => $current?->ends_at ?? ($company->status === CompanyStatus::Trial ? $company->trial_ends_at : null),
                ]));
                $this->alerts->planChanged($company, $quote, $by);

                return null;

            case PlanQuote::DOWNGRADE:
                $current->update(['scheduled_plan_id' => $plan->id, 'scheduled_interval' => $interval]);
                $company->unsetRelation('subscription');
                $this->alerts->planChanged($company, $quote, $by);

                return null;
        }

        $invoice = DB::transaction(function () use ($company, $current, $quote, $plan, $interval, $by) {
            // One open upgrade at a time: a newer choice replaces an unpaid older one.
            $company->planInvoices()->pending()->where('kind', PlanInvoice::KIND_UPGRADE)->get()
                ->each(fn (PlanInvoice $old) => $old->update([
                    'status' => InvoiceStatus::Void, 'void_reason' => 'Replaced by a newer plan change', 'voided_by' => $by?->id,
                ]));

            return PlanInvoice::query()->create([
                'company_id' => $company->id,
                'subscription_id' => $current?->id,
                'kind' => PlanInvoice::KIND_UPGRADE,
                'plan_id' => $plan->id,
                'interval' => $interval,
                'amount_cents' => $quote->amount,
                'currency' => $plan->currency,
                'description' => ($current?->plan?->name ?? 'No plan').' → '.$plan->name.': '.$quote->working,
                'period_starts_at' => now(),
                'period_ends_at' => $quote->periodEndsAt,
                'due_at' => now(),
                'status' => InvoiceStatus::Pending,
                'requested_by' => $by?->id,
            ]);
        });

        if ($invoice->amount_cents === 0) {
            $this->confirm($invoice, $by, 'other', 'Nothing to pay', quiet: true);
            $this->alerts->planChanged($company, $quote, $by);

            return $invoice;
        }

        if ($paid !== null) {
            return $this->confirm($invoice, $by, $paid['method'], $paid['reference'] ?? null);
        }

        $this->alerts->invoiceCreated($invoice, $by);

        return $invoice;
    }

    public function cancelScheduledDowngrade(Company $company, ?User $by): void
    {
        $current = $company->subscription()->first();
        $company->unsetRelation('subscription');

        if ($current?->scheduled_plan_id) {
            $current->update(['scheduled_plan_id' => null, 'scheduled_interval' => null]);
            $this->alerts->downgradeCancelled($company, $by);
        }
    }

    /** The owner says "I have paid" with a screenshot of the transfer. */
    public function sendProof(PlanInvoice $invoice, ?string $path, ?string $note, User $by): PlanInvoice
    {
        $this->assertPending($invoice);

        $invoice->update([
            'proof_path' => $path ?: $invoice->proof_path,
            'proof_note' => $note,
            'proof_sent_at' => now(),
        ]);

        $this->alerts->proofSent($invoice, $by);

        return $invoice;
    }

    /** The money arrived: mark paid and apply it (upgrade now, renewal extends, scheduled downgrade starts). */
    public function confirm(PlanInvoice $invoice, ?User $by, string $method, ?string $reference = null, bool $quiet = false): PlanInvoice
    {
        $this->assertPending($invoice);
        abort_unless(array_key_exists($method, PlanInvoice::METHODS), 422, 'Unknown payment method.');

        DB::transaction(function () use ($invoice, $by, $method, $reference) {
            $invoice->update([
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
                'paid_method' => $method,
                'paid_reference' => $reference,
                'confirmed_by' => $by?->id,
            ]);

            $company = $invoice->company;
            $current = $company->subscription()->with('plan')->first();
            $plan = $invoice->plan;

            if ($invoice->kind === PlanInvoice::KIND_UPGRADE) {
                $this->replace($company, $current, $plan, $invoice->interval, [
                    'status' => 'active', 'starts_at' => now(), 'ends_at' => $invoice->period_ends_at,
                ]);
            } elseif (! $current || $current->isTrial() || ! $current->plan?->is($plan) || $current->interval !== $invoice->interval) {
                // First payment after the trial, or a scheduled downgrade starting.
                $this->replace($company, $current, $plan, $invoice->interval, [
                    'status' => 'active', 'starts_at' => $invoice->period_starts_at, 'ends_at' => $invoice->period_ends_at,
                ], voidRenewals: false);
            } else {
                $current->update(['status' => 'active', 'ends_at' => $invoice->period_ends_at]);
            }

            if ($company->status === CompanyStatus::Trial) {
                $company->update(['status' => CompanyStatus::Active, 'trial_ends_at' => null]);
            }
        });

        $invoice->company->unsetRelation('subscription');

        // Quiet: nothing was paid (0), the caller tells the team itself.
        if (! $quiet) {
            $this->alerts->paid($invoice->fresh(['company', 'plan']), $by);
        }

        return $invoice;
    }

    public function void(PlanInvoice $invoice, ?User $by, string $reason): PlanInvoice
    {
        $this->assertPending($invoice);

        $invoice->update(['status' => InvoiceStatus::Void, 'void_reason' => $reason, 'voided_by' => $by?->id]);
        $this->alerts->voided($invoice, $by);

        return $invoice;
    }

    /**
     * Makes the invoice for the next period: a few days before the period ends (pay-per-order plans:
     * once it has ended, for its orders). Nothing to pay = marked paid at once. Returns how many.
     */
    public function issueDueInvoices(?Carbon $now = null): int
    {
        $now ??= now();
        $made = 0;

        Company::query()
            ->whereIn('status', [CompanyStatus::Trial->value, CompanyStatus::Active->value])
            ->with('subscription.plan', 'subscription.scheduledPlan')
            ->get()
            ->each(function (Company $company) use ($now, &$made) {
                $sub = $company->subscription;

                if (! $sub?->ends_at || $sub->ended_at || ! $sub->plan) {
                    return;
                }

                $perOrder = $sub->plan->isPerOrder();
                $issueFrom = $perOrder ? $sub->ends_at : $sub->ends_at->copy()->subDays((int) config('tok.invoice_days_before'));

                if ($now->lessThan($issueFrom)) {
                    return;
                }

                $exists = $company->planInvoices()
                    ->where('kind', '!=', PlanInvoice::KIND_UPGRADE)
                    ->where('status', '!=', InvoiceStatus::Void->value)
                    ->where('period_starts_at', $sub->ends_at)
                    ->exists();

                if ($exists) {
                    return;
                }

                $invoice = $this->makeRenewal($company, $sub);
                $made++;

                $invoice->amount_cents === 0
                    ? $this->confirm($invoice, null, 'other', 'Nothing to pay', quiet: true)
                    : $this->alerts->invoiceCreated($invoice);
            });

        return $made;
    }

    /** Unpaid renewals past the grace days: the back office is now read-only; tell the team once. */
    public function flagOverdue(?Carbon $now = null): int
    {
        $now ??= now();

        $late = PlanInvoice::query()
            ->pending()
            ->where('kind', '!=', PlanInvoice::KIND_UPGRADE)
            ->whereNull('overdue_notified_at')
            ->where('due_at', '<', $now->copy()->subDays((int) config('tok.billing_grace_days')))
            ->with('company', 'plan')
            ->get();

        $late->each(function (PlanInvoice $invoice) {
            $invoice->update(['overdue_notified_at' => now()]);
            $this->alerts->overdue($invoice);
        });

        return $late->count();
    }

    private function makeRenewal(Company $company, Subscription $sub): PlanInvoice
    {
        $plan = $sub->scheduledPlan ?? $sub->plan;
        $interval = $sub->scheduled_interval ?? $sub->interval;
        $start = $sub->ends_at->copy();
        $end = Subscription::addInterval($start, $interval);
        $fmt = fn (int $cents) => Money::format($cents, $plan->currency);

        if ($sub->plan->isPerOrder() && ! $sub->isTrial()) {
            // Pay-per-order: the orders of the period that just ended.
            $orders = Order::query()
                ->where('company_id', $company->id)
                ->where('status', '!=', OrderStatus::Cancelled->value)
                ->where('created_at', '>=', $sub->periodStartsAt())
                ->where('created_at', '<', $sub->ends_at)
                ->count();
            $price = (int) $sub->plan->price_per_order_cents;
            $amount = $orders * $price;
            $description = "{$orders} orders × {$fmt($price)} ({$sub->periodStartsAt()->format('j M')} – {$sub->ends_at->format('j M Y')})";
        } elseif ($plan->isPerOrder()) {
            $amount = 0;
            $description = 'Pay per order: billed at the end of the period';
        } else {
            // Same plan: the price agreed at the start. A scheduled downgrade or the first payment: today's price.
            $same = ! $sub->scheduledPlan && ! $sub->isTrial();
            $amount = $same ? (int) $sub->price_cents : $plan->priceFor($interval);
            $description = $plan->name.' '.($interval === 'yearly' ? 'yearly' : 'monthly').': '.$fmt($amount)
                .($sub->scheduledPlan ? ' (downgrade from '.$sub->plan->name.')' : '');
        }

        return PlanInvoice::query()->create([
            'company_id' => $company->id,
            'subscription_id' => $sub->id,
            'kind' => $sub->isTrial() ? PlanInvoice::KIND_FIRST : PlanInvoice::KIND_RENEWAL,
            'plan_id' => $plan->id,
            'interval' => $interval,
            'amount_cents' => $amount,
            'currency' => $plan->currency,
            'description' => $description,
            'period_starts_at' => $start,
            'period_ends_at' => $end,
            'due_at' => $start,
            'status' => InvoiceStatus::Pending,
        ]);
    }

    /** Ends the current subscription and starts a new one (the old row stays as history). */
    private function replace(Company $company, ?Subscription $current, Plan $plan, string $interval, array $attributes, bool $voidRenewals = true): Subscription
    {
        $current?->update(['status' => 'ended', 'ended_at' => now(), 'scheduled_plan_id' => null, 'scheduled_interval' => null]);

        if ($voidRenewals) {
            // An invoice for the old plan's next period no longer fits; the hourly run makes a new one.
            $company->planInvoices()->pending()->where('kind', '!=', PlanInvoice::KIND_UPGRADE)->get()
                ->each(fn (PlanInvoice $old) => $old->update([
                    'status' => InvoiceStatus::Void, 'void_reason' => 'Plan changed; a new invoice is made for the new plan',
                ]));
        }

        $company->unsetRelation('subscription');

        return Subscription::query()->create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'interval' => $interval,
            'price_cents' => $plan->isPerOrder() ? 0 : $plan->priceFor($interval),
            'currency' => $plan->currency,
            ...$attributes,
        ]);
    }

    private function assertPending(PlanInvoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Pending) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is already '.strtolower($invoice->status->getLabel()).'.']);
        }
    }
}
