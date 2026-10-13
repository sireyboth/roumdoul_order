<?php

namespace App\Filament\Shared;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscriptions\PlanQuote;
use App\Support\Money;
use App\Support\Terms;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The "Change plan" fields, the same for the Roumdoul team (/admin) and the owner (/app > Your plan):
 * pick a plan and monthly/yearly, and see at once whether it is an upgrade or a downgrade and what to pay.
 */
final class PlanChangeForm
{
    /**
     * @param  Closure(): ?Company  $company
     * @return array<int, mixed>
     */
    public static function fields(Closure $company, bool $includePerOrder): array
    {
        return [
            Select::make('plan_id')
                ->label('New plan')
                ->options(fn () => Plan::query()
                    ->where('is_active', true)
                    ->when(! $includePerOrder, fn ($q) => $q->whereNull('price_per_order_cents'))
                    ->orderBy('sort_order')
                    ->get()
                    ->mapWithKeys(fn (Plan $plan) => [$plan->id => $plan->name.' · '.$plan->priceLabel('monthly')]))
                ->required()
                ->live(),
            ToggleButtons::make('interval')
                ->label('Pay')
                ->options(Subscription::INTERVALS)
                ->default('monthly')
                ->inline()
                ->required()
                ->live()
                ->hidden(fn (Get $get) => (bool) self::plan($get)?->isPerOrder()),
            TextEntry::make('preview')
                ->label('What happens')
                ->state(fn (Get $get) => self::quote($company(), $get)?->summary() ?? 'Pick a plan to see the price.'),
        ];
    }

    /**
     * For owners: the key terms of this exact change, shown once more right above "Confirm",
     * and the required "I agree" tick box (stored with App\Support\Terms::record()).
     *
     * @param  Closure(): ?Company  $company
     * @return array<int, mixed>
     */
    public static function termsReminder(Closure $company): array
    {
        return [
            TextEntry::make('terms_reminder')
                ->label('Before you confirm')
                ->bulleted()
                ->state(fn (Get $get) => self::reminderLines(self::quote($company(), $get)))
                ->visible(fn (Get $get) => ! in_array(self::quote($company(), $get)?->kind, [null, PlanQuote::NONE], true)),
            Terms::checkbox()
                ->visible(fn (Get $get) => ! in_array(self::quote($company(), $get)?->kind, [null, PlanQuote::NONE], true)),
        ];
    }

    /** @return array<int, string> */
    public static function reminderLines(?PlanQuote $quote): array
    {
        if (! $quote) {
            return [];
        }

        $grace = (int) config('tok.billing_grace_days');
        $period = $quote->toInterval === 'yearly' ? 'year' : 'month';
        $renews = $quote->to->isPerOrder()
            ? "Billed after each {$period} for that {$period}'s orders ({$quote->to->priceLabel('monthly')})."
            : "Renews every {$period} at {$quote->to->priceLabel($quote->toInterval)}, paid in advance.";

        return array_values(array_filter([
            match ($quote->kind) {
                PlanQuote::UPGRADE => 'You pay '.Money::format($quote->amount, $quote->currency).' now by ABA or KHQR. '
                    .$quote->to->name.' starts as soon as Roumdoul confirms the payment.',
                PlanQuote::DOWNGRADE => $quote->to->name.' starts on '.$quote->startsAt?->format('j M Y')
                    .'. You keep '.$quote->from?->name.' until then. No refund for the rest of this period.',
                PlanQuote::TRIAL => 'Free until your trial ends; the first payment is asked then.',
                default => 'Switches now. Nothing to pay now.',
            },
            $renews,
            "The invoice comes {$grace} days before each renewal. Not paid {$grace} days after the due date: your back office becomes read-only (orders keep working).",
            $quote->kind === PlanQuote::UPGRADE ? 'Payments for a period that has started are not refunded.' : null,
        ]));
    }

    public static function quote(?Company $company, Get $get): ?PlanQuote
    {
        $plan = self::plan($get);

        if (! $company || ! $plan) {
            return null;
        }

        return PlanQuote::for($company->subscription()->with('plan')->first(), $plan, self::interval($get));
    }

    public static function plan(Get $get): ?Plan
    {
        return filled($get('plan_id')) ? Plan::query()->find($get('plan_id')) : null;
    }

    public static function interval(Get $get): string
    {
        return $get('interval') === 'yearly' ? 'yearly' : 'monthly';
    }
}
