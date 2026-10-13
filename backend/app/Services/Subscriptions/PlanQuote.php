<?php

namespace App\Services\Subscriptions;

use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * What changing plan would do, before anything is saved (docs/roadmap.md C2). Pure maths:
 *
 * - none:      same plan and interval.
 * - trial:     during the free trial: switch now, nothing to pay (the first invoice comes at the end of the trial).
 * - switch:    same price, or a pay-per-order plan is involved: switch now, nothing charged automatically.
 * - upgrade:   higher price: starts once paid; pay the difference for the days left
 *              ((new − old) × days left ÷ days in period, rounded down). A different interval starts a new
 *              period: pay the new price minus what is left of the old one.
 * - downgrade: lower price: starts at the next renewal, no refund.
 */
final class PlanQuote
{
    public const NONE = 'none';

    public const TRIAL = 'trial';

    public const SWITCH = 'switch';

    public const UPGRADE = 'upgrade';

    public const DOWNGRADE = 'downgrade';

    public function __construct(
        public readonly string $kind,
        public readonly ?Plan $from,
        public readonly Plan $to,
        public readonly ?string $fromInterval,
        public readonly string $toInterval,
        public readonly int $amount,
        public readonly string $currency,
        /** When the new plan starts (for an upgrade: once paid). */
        public readonly ?Carbon $startsAt,
        /** End of the period the payment covers (upgrade) or the current period (others). */
        public readonly ?Carbon $periodEndsAt,
        public readonly int $daysLeft,
        public readonly int $daysInPeriod,
        /** How the amount was worked out, e.g. "($39.00 − $19.00) × 15/31 days". */
        public readonly string $working,
    ) {}

    public static function for(?Subscription $current, Plan $to, string $interval, ?Carbon $now = null): self
    {
        $now ??= now();
        $currency = $to->currency;
        $from = $current?->plan;
        $fromInterval = $current?->interval;
        $newPrice = $to->priceFor($interval);
        $endsAt = $current?->ends_at;

        $make = fn (string $kind, int $amount = 0, ?Carbon $startsAt = null, ?Carbon $periodEnd = null, int $left = 0, int $total = 0, string $working = '') => new self(
            $kind, $from, $to, $fromInterval, $interval, $amount, $currency, $startsAt, $periodEnd ?? $endsAt, $left, $total, $working,
        );

        if ($current && $from && $from->is($to) && $fromInterval === $interval) {
            return $make(self::NONE);
        }

        if ($current?->isTrial()) {
            return $make(self::TRIAL, startsAt: $now);
        }

        if ($to->isPerOrder() || $from?->isPerOrder()) {
            return $make(self::SWITCH, startsAt: $now);
        }

        $oldPrice = $current ? (int) $current->price_cents : 0;
        // No paid period running (no subscription, no end date, or already ended): nothing left to credit.
        $paidPeriod = $current && $endsAt && $endsAt->greaterThan($now);
        $total = $paidPeriod ? max(1, (int) round($current->periodStartsAt()->diffInDays($endsAt))) : 0;
        $left = $paidPeriod ? min($total, (int) ceil($now->diffInSeconds($endsAt) / 86400)) : 0;
        $fmt = fn (int $cents) => Money::format($cents, $currency);

        if ($newPrice === $oldPrice) {
            return $make(self::SWITCH, startsAt: $now);
        }

        if ($newPrice < $oldPrice) {
            return $paidPeriod
                ? $make(self::DOWNGRADE, startsAt: $endsAt, left: $left, total: $total)
                : $make(self::SWITCH, startsAt: $now);
        }

        if ($paidPeriod && $fromInterval === $interval) {
            $amount = intdiv(($newPrice - $oldPrice) * $left, $total);

            return $make(self::UPGRADE, $amount, $now, $endsAt, $left, $total,
                "({$fmt($newPrice)} − {$fmt($oldPrice)}) × {$left}/{$total} days");
        }

        // New period from now (different interval, or nothing paid yet): new price minus the unused part of the old one.
        $credit = $paidPeriod ? (int) ceil($oldPrice * $left / $total) : 0;
        $periodEnd = Subscription::addInterval($now, $interval);

        return $make(self::UPGRADE, max(0, $newPrice - $credit), $now, $periodEnd, $left, $total,
            $credit > 0
                ? "{$fmt($newPrice)} − {$fmt($credit)} unused ({$fmt($oldPrice)} × {$left}/{$total} days)"
                : "{$fmt($newPrice)} for one ".($interval === 'yearly' ? 'year' : 'month'));
    }

    /** One plain sentence for the confirm window and the Telegram message. */
    public function summary(): string
    {
        $to = "{$this->to->name} ({$this->to->priceLabel($this->toInterval)})";
        $amount = Money::format($this->amount, $this->currency);

        return match ($this->kind) {
            self::NONE => 'This is already the current plan.',
            self::TRIAL => "Switches to {$to} now. Still free until the trial ends; the first payment is asked then.",
            self::SWITCH => "Switches to {$to} now. Nothing to pay now.",
            self::UPGRADE => "Upgrade to {$to}. To pay now: {$amount} = {$this->working}. Starts as soon as the payment is confirmed"
                .($this->periodEndsAt ? ', paid until '.$this->periodEndsAt->format('j M Y').'.' : '.'),
            self::DOWNGRADE => "Downgrade to {$to} on {$this->startsAt?->format('j M Y')} (next renewal). Stays on {$this->from?->name} until then. No refund.",
        };
    }
}
