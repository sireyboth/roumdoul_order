<?php

namespace App\Filament\Admin\Resources\Companies;

use App\Enums\CompanyStatus;
use App\Filament\Admin\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            Grid::make(2)->schema([
                TextInput::make('name')->required(),
                TextInput::make('slug')->required()->alphaDash()->unique(ignoreRecord: true),
                Select::make('status')->options(CompanyStatus::class)->required(),
                DateTimePicker::make('trial_ends_at'),
                TextInput::make('phone'),
                TextInput::make('email')->email(),
                TextInput::make('coreos_company_id')->label('coreos company ID')->numeric(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('subscription.plan'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold')->description(fn (Company $record) => $record->slug),
                TextColumn::make('status')->badge(),
                TextColumn::make('subscription.plan.name')->label('Plan')->placeholder('—'),
                TextColumn::make('branches_count')->label('Branches')->counts('branches'),
                TextColumn::make('dining_tables_count')->label('Tables')->counts('diningTables'),
                TextColumn::make('trial_ends_at')->dateTime('d M Y')->placeholder('—'),
                TextColumn::make('created_at')->since()->label('Joined'),
            ])
            ->filters([
                SelectFilter::make('status')->options(CompanyStatus::class),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('changePlan')
                    ->label('Change plan')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->modalDescription('Limits change right away. If the restaurant already has more branches, tables or staff than the new plan allows, nothing is removed; it just cannot add more.')
                    ->fillForm(fn (Company $record) => ['plan_id' => $record->subscription?->plan_id])
                    ->schema([
                        Select::make('plan_id')
                            ->label('Plan')
                            ->options(fn () => Plan::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'))
                            ->required(),
                    ])
                    ->action(fn (Company $record, array $data) => static::changePlan($record, Plan::query()->findOrFail($data['plan_id']))),
            ]);
    }

    /** Moves a restaurant to another plan (the subscription change is audit-logged). */
    public static function changePlan(Company $company, Plan $plan): void
    {
        $subscription = $company->subscription;

        if ($subscription) {
            $subscription->update(['plan_id' => $plan->id]);
        } else {
            Subscription::query()->create([
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'status' => $company->status === CompanyStatus::Trial ? 'trialing' : 'active',
                'interval' => 'monthly',
                'starts_at' => now(),
                'ends_at' => $company->status === CompanyStatus::Trial ? $company->trial_ends_at : null,
            ]);
        }

        $company->unsetRelation('subscription');

        $over = collect(['branches', 'tables', 'staff'])
            ->filter(fn (string $resource) => $company->limitFor($resource) !== null
                && static::countOf($company, $resource) > $company->limitFor($resource));

        $notification = Notification::make()->title("{$company->name} is now on {$plan->name}");

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
