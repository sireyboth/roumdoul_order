<?php

namespace App\Services\Subscriptions;

use App\Enums\StaffRole;
use App\Models\Company;
use App\Models\PlanInvoice;
use App\Models\User;
use App\Services\TelegramNotifier;
use App\Support\Terms;
use App\Support\Uploads;

/**
 * Messages to the Roumdoul team's Telegram group (PLATFORM_TELEGRAM_CHAT_ID): new restaurants,
 * plan changes, invoices and payments. Every message names the restaurant, how to reach the
 * owner, what happened and what to do next. Does nothing when the group is not set.
 */
class PlatformAlerts
{
    public function __construct(private TelegramNotifier $telegram) {}

    public function newRestaurant(Company $company, User $owner): void
    {
        $sub = $company->subscription;

        $this->send('🆕 <b>New restaurant signed up</b>', $company, [
            'Plan: '.e($sub?->plan?->name ?? '—').' (free trial until '.($company->trial_ends_at?->format('j M Y') ?? '—').')',
            'Owner: '.e($owner->name).' · '.e($owner->email),
            'Agreed to Terms v'.e(Terms::version()),
        ], 'Call them to help set up the menu and tables.');
    }

    public function planChanged(Company $company, PlanQuote $quote, ?User $by): void
    {
        $title = match ($quote->kind) {
            PlanQuote::DOWNGRADE => '⬇️ <b>Downgrade scheduled</b>',
            PlanQuote::TRIAL => '🔁 <b>Plan changed during trial</b>',
            default => '🔁 <b>Plan changed</b>',
        };

        $this->send($title, $company, [
            e(($quote->from?->name ?? '—').' → '.$quote->to->name.' ('.$quote->to->priceLabel($quote->toInterval).')'),
            e($quote->summary()),
            $this->by($by),
        ]);
    }

    public function downgradeCancelled(Company $company, ?User $by): void
    {
        $this->send('↩️ <b>Scheduled downgrade cancelled</b>', $company, [
            'Stays on '.e($company->subscription?->plan?->name ?? '—').'.',
            $this->by($by),
        ]);
    }

    public function invoiceCreated(PlanInvoice $invoice, ?User $by = null): void
    {
        $title = $invoice->kind === PlanInvoice::KIND_UPGRADE ? '⬆️ <b>Upgrade requested</b>' : '🧾 <b>Invoice to collect</b>';

        $this->send($title, $invoice->company, [
            ...$this->invoiceLines($invoice),
            'Due: '.$invoice->due_at->format('j M Y'),
            $this->by($by),
        ], 'Wait for their ABA transfer, then confirm it in /admin > Plan invoices.');
    }

    public function proofSent(PlanInvoice $invoice, User $by): void
    {
        $this->send('💸 <b>Payment sent, please check</b>', $invoice->company, [
            ...$this->invoiceLines($invoice),
            $invoice->proof_note ? 'Note: '.e($invoice->proof_note) : null,
            $invoice->proof_path ? 'Screenshot: '.e((string) Uploads::url($invoice->proof_path)) : null,
            $this->by($by),
        ], 'Check your ABA account, then confirm in /admin > Plan invoices.');
    }

    public function paid(PlanInvoice $invoice, ?User $by): void
    {
        $sub = $invoice->company->subscription()->with('plan')->first();

        $this->send('✅ <b>Payment confirmed</b>', $invoice->company, [
            ...$this->invoiceLines($invoice),
            'Method: '.e(PlanInvoice::METHODS[$invoice->paid_method] ?? '—').($invoice->paid_reference ? ' · Ref '.e($invoice->paid_reference) : ''),
            'Now on: '.e($sub?->plan?->name ?? '—').', paid until '.($sub?->ends_at?->format('j M Y') ?? '—'),
            $this->by($by, 'Confirmed by'),
        ]);
    }

    public function voided(PlanInvoice $invoice, ?User $by): void
    {
        $this->send('🚫 <b>Invoice voided</b>', $invoice->company, [
            ...$this->invoiceLines($invoice),
            'Reason: '.e((string) $invoice->void_reason),
            $this->by($by),
        ]);
    }

    public function overdue(PlanInvoice $invoice): void
    {
        $this->send('⛔ <b>Not paid: back office is now read-only</b>', $invoice->company, [
            ...$this->invoiceLines($invoice),
            'Was due: '.$invoice->due_at->format('j M Y').' ('.config('tok.billing_grace_days').' days grace passed)',
            'Orders and staff screens still work; the owner cannot change anything in the back office.',
        ], 'Call them about the payment.');
    }

    /** @return array<int, string> */
    private function invoiceLines(PlanInvoice $invoice): array
    {
        $invoice->loadMissing('plan');

        return [
            e($invoice->number().' · '.$invoice->kindLabel().' · '.$invoice->plan->name.' ('.$invoice->interval.')'),
            '<b>Amount: '.e($invoice->amount()).'</b> = '.e($invoice->description),
            'Period: '.$invoice->period_starts_at->format('j M Y').' – '.$invoice->period_ends_at->format('j M Y'),
        ];
    }

    private function by(?User $user, string $label = 'By'): ?string
    {
        if (! $user) {
            return null;
        }

        return $label.': '.e($user->name).($user->is_platform_admin
            ? ' (Roumdoul team)'
            : ' (restaurant, agreed to Terms v'.e(Terms::version()).')');
    }

    /** @param  array<int, string|null>  $lines */
    private function send(string $title, Company $company, array $lines, ?string $todo = null): void
    {
        $chat = config('services.telegram.platform_chat_id');

        if (! $chat) {
            return;
        }

        $owner = $company->users()->wherePivot('role', StaffRole::Owner->value)->orderBy('company_user.id')->first();
        $contact = array_filter([
            $company->phone ? '📞 '.e($company->phone) : null,
            $company->contact_telegram ? '✈️ '.e($company->contact_telegram) : null,
            ($company->email ?? $owner?->email) ? '✉️ '.e($company->email ?? $owner?->email) : null,
        ]);

        $text = implode("\n", array_filter([
            $title,
            '🏪 <b>'.e($company->name).'</b> · /app/'.e($company->slug),
            $owner ? '👤 '.e($owner->name) : null,
            $contact ? implode(' · ', $contact) : '📞 no phone or Telegram given',
            '',
            ...$lines,
            $todo ? "\n👉 ".e($todo) : null,
            '🔗 '.rtrim((string) config('app.url'), '/').'/admin',
        ], fn ($line) => $line !== null));

        $this->telegram->send((string) $chat, $text, config('services.telegram.platform_bot_token'));
    }
}
