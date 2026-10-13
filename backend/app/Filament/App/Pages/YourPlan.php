<?php

namespace App\Filament\App\Pages;

use App\Enums\InvoiceStatus;
use App\Enums\StaffRole;
use App\Filament\Shared\PlanChangeForm;
use App\Models\Plan;
use App\Models\PlanInvoice;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Subscriptions\PlanQuote;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Money;
use App\Support\Tenant;
use App\Support\Terms;
use App\Support\Uploads;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * The owner's plan: what they pay, until when, invoices to pay (with Roumdoul's ABA QR),
 * "I have paid" with a screenshot, and changing plan (docs/roadmap.md C2). Owners only.
 * Works even when the back office is read-only, so a late restaurant can always pay.
 */
class YourPlan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Your plan';

    protected static ?string $title = 'Your plan';

    protected static string|\UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?int $navigationSort = 99;

    public static function canAccess(): bool
    {
        $company = Tenant::current();
        $user = Auth::user();

        return $company && $user instanceof User && $user->roleIn($company) === StaffRole::Owner;
    }

    /** Something to pay: shown as a badge in the menu. */
    public static function getNavigationBadge(): ?string
    {
        $count = Tenant::current()?->planInvoices()->pending()->count() ?? 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function content(Schema $schema): Schema
    {
        $company = Tenant::current();
        $sub = $company->subscription()->with('plan', 'scheduledPlan')->first();
        $pending = $company->planInvoices()->pending()->with('plan')->orderBy('due_at')->get();
        $history = $company->planInvoices()->where('status', '!=', InvoiceStatus::Pending->value)->with('plan')->latest('id')->limit(12)->get();
        $settings = PlatformSetting::allValues();

        $current = Section::make('Current plan')
            ->columns(['default' => 1, 'md' => 3])
            ->schema([
                TextEntry::make('plan')->label('Plan')->state($sub?->plan?->name ?? 'No plan')->weight('bold'),
                TextEntry::make('price')->label('Price')->state($sub ? ($sub->plan?->isPerOrder() ? $sub->plan->priceLabel('monthly') : $sub->price()) : '—'),
                TextEntry::make('until')
                    ->label($sub?->isTrial() ? 'Free trial until' : 'Paid until')
                    ->state($sub?->ends_at?->format('j M Y') ?? '—'),
                TextEntry::make('scheduled')
                    ->label('Coming change')
                    ->state($sub?->scheduledPlan
                        ? "Moves to {$sub->scheduledPlan->name} on {$sub->ends_at?->format('j M Y')} (downgrade, no refund)."
                        : null)
                    ->visible((bool) $sub?->scheduledPlan)
                    ->columnSpanFull(),
                TextEntry::make('terms')
                    ->label('Terms')
                    ->state(new HtmlString('<a href="'.e(Terms::url()).'" target="_blank" rel="noopener" class="underline">Terms and conditions</a> (version '.e(Terms::version()).')'))
                    ->html()
                    ->columnSpanFull(),
            ]);

        $toPay = $pending->map(fn (PlanInvoice $invoice) => Section::make("{$invoice->number()} · {$invoice->amount()}")
            ->description($invoice->kindLabel().' · due '.$invoice->due_at->format('j M Y')
                .($invoice->proof_sent_at ? ' · payment sent '.$invoice->proof_sent_at->format('j M H:i').', waiting for Roumdoul to confirm' : ''))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->iconColor($invoice->proof_sent_at ? 'gray' : 'warning')
            ->columns(['default' => 1, 'md' => 2])
            ->schema([
                TextEntry::make("i{$invoice->id}_what")->label('For')
                    ->state("{$invoice->plan->name} ({$invoice->interval}), {$invoice->period_starts_at->format('j M Y')} – {$invoice->period_ends_at->format('j M Y')}"),
                TextEntry::make("i{$invoice->id}_how")->label('Amount')->state($invoice->description),
            ]))->all();

        $howToPay = Section::make('How to pay')
            ->description('Scan with ABA or any KHQR bank app, then tap "I have paid" and add the screenshot.')
            ->visible($pending->isNotEmpty())
            ->columns(['default' => 1, 'md' => 2])
            ->schema([
                ImageEntry::make('qr')->hiddenLabel()->state($settings['payment_qr_path'])->disk(Uploads::disk())->imageHeight(240)
                    ->placeholder('Ask the Roumdoul team for the payment QR.'),
                Grid::make(1)->schema([
                    TextEntry::make('account_name')->label('Account name')->state($settings['payment_account_name'])->placeholder('—'),
                    TextEntry::make('account_number')->label('Account number')->state($settings['payment_account_number'])->placeholder('—')->copyable(),
                    TextEntry::make('note')->hiddenLabel()->state($settings['payment_note'])->visible(filled($settings['payment_note'])),
                    TextEntry::make('contact')->label('Questions?')
                        ->state(collect([$settings['support_phone'], $settings['support_telegram']])->filter()->implode(' · ') ?: null)
                        ->placeholder('—'),
                ])->columnSpan(1),
            ]);

        $past = Section::make('Past invoices')
            ->collapsed()
            ->visible($history->isNotEmpty())
            ->schema($history->map(fn (PlanInvoice $invoice) => TextEntry::make("h{$invoice->id}")
                ->hiddenLabel()
                ->state("{$invoice->number()} · {$invoice->kindLabel()} · {$invoice->plan->name} · {$invoice->amount()} · "
                    .$invoice->status->getLabel()
                    .($invoice->paid_at ? ' '.$invoice->paid_at->format('j M Y') : '')
                    .($invoice->void_reason ? " ({$invoice->void_reason})" : '')))->all());

        return $schema->components([$current, ...$toPay, $howToPay, $past]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('paid')
                ->label('I have paid')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(fn () => Tenant::current()->planInvoices()->pending()->exists())
                ->modalDescription('Send us the screenshot of your transfer. The Roumdoul team checks it and confirms, usually the same day.')
                ->schema([
                    Select::make('invoice_id')
                        ->label('Invoice')
                        ->options(fn () => Tenant::current()->planInvoices()->pending()->orderBy('due_at')->get()
                            ->mapWithKeys(fn (PlanInvoice $i) => [$i->id => "{$i->number()} · {$i->amount()}"]))
                        ->default(fn () => Tenant::current()->planInvoices()->pending()->orderBy('due_at')->value('id'))
                        ->required(),
                    FileUpload::make('proof_path')
                        ->label('Screenshot of the transfer')
                        ->image()
                        ->disk(Uploads::disk())
                        ->directory('payment-proofs')
                        ->maxSize(4096)
                        ->required(),
                    TextInput::make('note')->label('Note (optional)')->placeholder('e.g. paid from Sokha\'s account')->maxLength(255),
                ])
                ->action(function (array $data) {
                    $invoice = Tenant::current()->planInvoices()->pending()->findOrFail($data['invoice_id']);
                    app(SubscriptionService::class)->sendProof($invoice, $data['proof_path'] ?? null, $data['note'] ?? null, Auth::user());
                    Notification::make()->title('Thank you! We will confirm your payment soon.')->success()->send();
                }),
            Action::make('changePlan')
                ->label('Change plan')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->color('gray')
                ->modalDescription('Upgrade: starts once paid, you pay only the difference for the days left. Downgrade: starts at your next renewal, no refund. Pay-per-order plans: please call us.')
                ->fillForm(fn () => [
                    'plan_id' => Tenant::current()->subscription?->plan_id,
                    'interval' => Tenant::current()->subscription?->interval ?? 'monthly',
                ])
                ->schema(fn () => [
                    ...PlanChangeForm::fields(fn () => Tenant::current(), includePerOrder: false),
                    // The key terms of this exact change once more, and "I agree", right above Confirm.
                    ...PlanChangeForm::termsReminder(fn () => Tenant::current()),
                ])
                ->modalSubmitActionLabel('Confirm')
                ->action(function (array $data) {
                    $company = Tenant::current();
                    $plan = Plan::query()->where('is_active', true)->whereNull('price_per_order_cents')->findOrFail($data['plan_id']);
                    $interval = $data['interval'] ?? 'monthly';
                    $quote = app(SubscriptionService::class)->quote($company, $plan, $interval);

                    if ($quote->kind !== PlanQuote::NONE) {
                        Terms::record($company, Auth::user(), Terms::PLAN_CHANGE, ($quote->from?->name ?? '—').' → '.$plan->name." ({$interval}), "
                            .($quote->kind === PlanQuote::UPGRADE ? Money::format($quote->amount, $quote->currency).' now' : $quote->kind));
                    }

                    $invoice = app(SubscriptionService::class)->change($company, $plan, $interval, Auth::user());

                    Notification::make()
                        ->title(match ($quote->kind) {
                            PlanQuote::NONE => 'That is already your plan.',
                            PlanQuote::DOWNGRADE => "You move to {$plan->name} on {$quote->startsAt?->format('j M Y')}.",
                            PlanQuote::UPGRADE => $invoice?->status === InvoiceStatus::Pending
                                ? "Please pay {$invoice->amount()}. {$plan->name} starts as soon as we confirm it."
                                : "You are now on {$plan->name}.",
                            default => "You are now on {$plan->name}.",
                        })
                        ->success()
                        ->send();
                }),
            Action::make('cancelDowngrade')
                ->label('Keep my plan')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Cancel the planned downgrade and stay on your current plan.')
                ->visible(fn () => (bool) Tenant::current()->subscription()->value('scheduled_plan_id'))
                ->action(function () {
                    app(SubscriptionService::class)->cancelScheduledDowngrade(Tenant::current(), Auth::user());
                    Notification::make()->title('Downgrade cancelled. Your plan stays the same.')->success()->send();
                }),
        ];
    }
}
