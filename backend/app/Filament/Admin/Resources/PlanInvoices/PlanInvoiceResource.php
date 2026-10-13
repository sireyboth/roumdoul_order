<?php

namespace App\Filament\Admin\Resources\PlanInvoices;

use App\Enums\InvoiceStatus;
use App\Filament\Admin\Resources\PlanInvoices\Pages\ListPlanInvoices;
use App\Models\PlanInvoice;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Uploads;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * What restaurants owe Roumdoul for their plan. Confirm a payment once the money is in the
 * ABA account (that applies the upgrade / renewal), or void with a reason. Never deleted.
 */
class PlanInvoiceResource extends Resource
{
    protected static ?string $model = PlanInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Plan invoices';

    /** Waiting for payment: shown as a number in the menu. */
    public static function getNavigationBadge(): ?string
    {
        $count = PlanInvoice::query()->pending()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'md' => 2])->columnSpanFull()->schema([
                TextEntry::make('number')->label('Invoice')->state(fn (PlanInvoice $record) => $record->number()),
                TextEntry::make('status')->badge(),
                TextEntry::make('company.name')->label('Restaurant'),
                TextEntry::make('contact')->label('Contact')
                    ->state(fn (PlanInvoice $record) => collect([$record->company->phone, $record->company->contact_telegram, $record->company->email])->filter()->implode(' · ') ?: '—'),
                TextEntry::make('kind')->state(fn (PlanInvoice $record) => $record->kindLabel()),
                TextEntry::make('plan.name')->label('Plan')->suffix(fn (PlanInvoice $record) => ' ('.$record->interval.')'),
                TextEntry::make('amount')->state(fn (PlanInvoice $record) => $record->amount())->weight('bold'),
                TextEntry::make('description')->label('How it was worked out'),
                TextEntry::make('period')->state(fn (PlanInvoice $record) => $record->period_starts_at->format('j M Y').' – '.$record->period_ends_at->format('j M Y')),
                TextEntry::make('due_at')->label('Due')->date('j M Y'),
                TextEntry::make('requester.name')->label('Asked by')->placeholder('Automatic (renewal)'),
                TextEntry::make('proof_note')->label('Owner\'s note')->placeholder('—'),
                ImageEntry::make('proof_path')->label('Transfer screenshot')->disk(Uploads::disk())->imageHeight(320)->placeholder('Not sent yet')->columnSpanFull(),
                TextEntry::make('paid')->label('Payment')
                    ->state(fn (PlanInvoice $record) => $record->paid_at
                        ? $record->paid_at->format('j M Y H:i').' · '.(PlanInvoice::METHODS[$record->paid_method] ?? $record->paid_method)
                            .($record->paid_reference ? ' · '.$record->paid_reference : '').' · by '.($record->confirmer?->name ?? 'system')
                        : '—'),
                TextEntry::make('void_reason')->label('Void reason')->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('company', 'plan'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('Invoice')->formatStateUsing(fn (PlanInvoice $record) => $record->number())->searchable(),
                TextColumn::make('company.name')->label('Restaurant')->searchable()->weight('bold')
                    ->description(fn (PlanInvoice $record) => $record->company->phone ?: $record->company->contact_telegram),
                TextColumn::make('kind')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => ucfirst($state))->visibleFrom('md'),
                TextColumn::make('plan.name')->label('Plan')->visibleFrom('md'),
                TextColumn::make('amount_cents')->label('Amount')->formatStateUsing(fn (PlanInvoice $record) => $record->amount())->alignEnd(),
                TextColumn::make('status')->badge(),
                IconColumn::make('proof_sent_at')->label('Paid, says owner')->boolean()->getStateUsing(fn (PlanInvoice $record) => $record->proof_sent_at !== null)->visibleFrom('lg'),
                TextColumn::make('due_at')->label('Due')->date('d M Y')->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('status')->options(InvoiceStatus::class)->default(InvoiceStatus::Pending->value),
                SelectFilter::make('kind')->options([
                    PlanInvoice::KIND_FIRST => 'First payment', PlanInvoice::KIND_RENEWAL => 'Renewal', PlanInvoice::KIND_UPGRADE => 'Upgrade',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('confirm')
                    ->label('Confirm payment')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (PlanInvoice $record) => $record->status === InvoiceStatus::Pending)
                    ->modalDescription(fn (PlanInvoice $record) => "{$record->company->name} paid {$record->amount()}? Check your ABA account first. This applies the plan at once.")
                    ->schema([
                        Select::make('method')->label('Paid by')->options(PlanInvoice::METHODS)->default('aba')->required(),
                        TextInput::make('reference')->label('Transfer reference')->maxLength(120),
                    ])
                    ->action(function (PlanInvoice $record, array $data) {
                        app(SubscriptionService::class)->confirm($record, Auth::user(), $data['method'], $data['reference'] ?? null);
                        Notification::make()->title("{$record->number()} paid. Plan applied.")->success()->send();
                    }),
                Action::make('void')
                    ->label('Void')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (PlanInvoice $record) => $record->status === InvoiceStatus::Pending)
                    ->schema([TextInput::make('reason')->label('Reason')->required()->maxLength(255)])
                    ->action(function (PlanInvoice $record, array $data) {
                        app(SubscriptionService::class)->void($record, Auth::user(), $data['reason']);
                        Notification::make()->title("{$record->number()} voided")->success()->send();
                    }),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListPlanInvoices::route('/')];
    }
}
