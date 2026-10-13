<?php

namespace App\Filament\Admin\Resources\Companies;

use App\Enums\CompanyStatus;
use App\Filament\Admin\Resources\Companies\Pages\ListCompanies;
use App\Filament\Shared\PlanChangeForm;
use App\Models\Company;
use App\Models\Plan;
use App\Models\PlanInvoice;
use App\Services\Subscriptions\PlanQuote;
use App\Services\Subscriptions\SubscriptionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/** Every restaurant on the platform. */
class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $navigationLabel = 'Restaurants';

    protected static ?string $modelLabel = 'restaurant';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->columnSpanFull()->schema([
                TextInput::make('name')->required(),
                TextInput::make('slug')->required()->alphaDash()->unique(ignoreRecord: true),
                Select::make('status')->options(CompanyStatus::class)->required(),
                DateTimePicker::make('trial_ends_at'),
                TextInput::make('phone'),
                TextInput::make('contact_telegram')->label('Telegram')->placeholder('@username or phone'),
                TextInput::make('email')->email(),
                TextInput::make('coreos_company_id')->label('coreos company ID')->numeric(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('subscription.plan', 'subscription.scheduledPlan'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold')->description(fn (Company $record) => $record->slug),
                TextColumn::make('status')->badge()
                    ->state(fn (Company $record) => $record->isReadOnly() ? 'Read-only (not paid)' : $record->status->getLabel())
                    ->color(fn (Company $record) => $record->isReadOnly() ? 'danger' : $record->status->getColor()),
                TextColumn::make('subscription.plan.name')->label('Plan')->placeholder('—')
                    ->description(fn (Company $record) => $record->subscription?->scheduledPlan
                        ? '→ '.$record->subscription->scheduledPlan->name.' on '.$record->subscription->ends_at?->format('j M')
                        : $record->subscription?->price()),
                TextColumn::make('subscription.ends_at')->label('Paid until')->date('d M Y')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('phone')->label('Contact')->placeholder('—')
                    ->description(fn (Company $record) => $record->contact_telegram)
                    ->searchable(['phone', 'contact_telegram', 'email'])
                    ->visibleFrom('md'),
                TextColumn::make('terms')->label('Terms')->placeholder('Not agreed yet')
                    ->state(fn (Company $record) => ($t = $record->termsAcceptances()->latest('id')->first())
                        ? 'v'.$t->version.' · '.$t->created_at->format('j M Y')
                        : null)
                    ->visibleFrom('lg'),
                TextColumn::make('branches_count')->label('Branches')->counts('branches')->visibleFrom('lg'),
                TextColumn::make('dining_tables_count')->label('Tables')->counts('diningTables')->visibleFrom('lg'),
                TextColumn::make('created_at')->since()->label('Joined')->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('status')->options(CompanyStatus::class),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('changePlan')
                    ->label('Change plan')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->modalDescription('Upgrades start once paid (pay the difference for the days left). Downgrades start at the next renewal, with no refund. Limits never delete anything.')
                    ->fillForm(fn (Company $record) => [
                        'plan_id' => $record->subscription?->plan_id,
                        'interval' => $record->subscription?->interval ?? 'monthly',
                    ])
                    ->schema(fn (Company $record) => [
                        ...PlanChangeForm::fields(fn () => $record, includePerOrder: true),
                        Toggle::make('paid')
                            ->label('They already paid this amount')
                            ->live()
                            ->visible(fn (Get $get) => PlanChangeForm::quote($record, $get)?->kind === PlanQuote::UPGRADE),
                        Select::make('method')
                            ->label('Paid by')
                            ->options(PlanInvoice::METHODS)
                            ->default('aba')
                            ->required()
                            ->visible(fn (Get $get) => (bool) $get('paid') && PlanChangeForm::quote($record, $get)?->kind === PlanQuote::UPGRADE),
                        TextInput::make('reference')
                            ->label('Transfer reference')
                            ->maxLength(120)
                            ->visible(fn (Get $get) => (bool) $get('paid') && PlanChangeForm::quote($record, $get)?->kind === PlanQuote::UPGRADE),
                    ])
                    ->action(fn (Company $record, array $data) => static::changePlan(
                        $record,
                        Plan::query()->findOrFail($data['plan_id']),
                        $data['interval'] ?? 'monthly',
                        ! empty($data['paid']) ? ['method' => $data['method'] ?? 'aba', 'reference' => $data['reference'] ?? null] : null,
                    )),
                Action::make('cancelDowngrade')
                    ->label('Cancel downgrade')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Company $record) => 'Keeps '.$record->subscription?->plan?->name.' after '.$record->subscription?->ends_at?->format('j M Y').'.')
                    ->visible(fn (Company $record) => (bool) $record->subscription?->scheduled_plan_id)
                    ->action(function (Company $record) {
                        app(SubscriptionService::class)->cancelScheduledDowngrade($record, Auth::user());
                        Notification::make()->title('Downgrade cancelled')->success()->send();
                    }),
            ]);
    }

    /**
     * Moves a restaurant to another plan through SubscriptionService (upgrade = invoice, paid now
     * if $paid; downgrade = at the next renewal). Warns when it already has more than the new limits.
     *
     * @param  array{method: string, reference?: string|null}|null  $paid
     */
    public static function changePlan(Company $company, Plan $plan, string $interval = 'monthly', ?array $paid = null): void
    {
        $quote = app(SubscriptionService::class)->quote($company, $plan, $interval);
        $invoice = app(SubscriptionService::class)->change($company, $plan, $interval, Auth::user(), $paid);
        $company->unsetRelation('subscription');

        $over = collect(['branches', 'tables', 'staff'])
            ->filter(fn (string $resource) => $plan->{'max_'.$resource} !== null
                && static::countOf($company, $resource) > $plan->{'max_'.$resource});

        $title = match (true) {
            $quote->kind === PlanQuote::NONE => "{$company->name} is already on {$plan->name}",
            $quote->kind === PlanQuote::DOWNGRADE => "{$company->name} moves to {$plan->name} on ".$quote->startsAt?->format('j M Y'),
            $invoice?->status?->value === 'pending' => "Invoice {$invoice->number()} for {$invoice->amount()} made. {$plan->name} starts once it is paid.",
            default => "{$company->name} is now on {$plan->name}",
        };

        $notification = Notification::make()->title($title);

        $over->isEmpty()
            ? $notification->success()
            : $notification->warning()->body('Already over the new limit for: '.$over->implode(', ').'. Nothing was removed.');

        $notification->send();
    }

    private static function countOf(Company $company, string $resource): int
    {
        return match ($resource) {
            'branches' => $company->branches()->count(),
            'tables' => $company->diningTables()->count(),
            'staff' => $company->memberships()->count(),
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListCompanies::route('/')];
    }
}
