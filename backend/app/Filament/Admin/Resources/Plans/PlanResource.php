<?php

namespace App\Filament\Admin\Resources\Plans;

use App\Filament\Admin\Resources\Plans\Pages\ListPlans;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    public static function form(Schema $schema): Schema
    {
        $money = fn (string $field) => TextInput::make($field)
            ->numeric()->minValue(0)->step(0.01)->prefix('$')
            ->formatStateUsing(fn ($state) => Money::fromMinor($state, 'USD'))
            ->dehydrateStateUsing(fn ($state) => Money::toMinor($state, 'USD') ?? 0);

        return $schema->components([
            Grid::make(3)->schema([
                TextInput::make('name')->required(),
                TextInput::make('code')->required()->alphaDash()->unique(ignoreRecord: true),
                Toggle::make('is_active')->default(true)->inline(false),
                TextInput::make('description')->columnSpan(3),
                TextInput::make('max_branches')->numeric()->integer()->minValue(1)->placeholder('Unlimited'),
                TextInput::make('max_tables')->numeric()->integer()->minValue(1)->placeholder('Unlimited'),
                TextInput::make('max_staff')->numeric()->integer()->minValue(1)->placeholder('Unlimited'),
                $money('price_monthly_cents')->label('Price / month'),
                $money('price_yearly_cents')->label('Price / year'),
                TextInput::make('price_per_order_cents')->label('Price / order')
                    ->numeric()->minValue(0.01)->step(0.01)->prefix('$')
                    ->placeholder('Monthly plan')
                    ->helperText('Fill only for a pay-per-order plan. The start page shows it as "From $x per order", without details.')
                    ->formatStateUsing(fn ($state) => Money::fromMinor($state, 'USD'))
                    ->dehydrateStateUsing(fn ($state) => Money::toMinor($state, 'USD')),
            ]),
            CheckboxList::make('features')->options(fn () => PlanFeature::options())->columns(2)
                ->descriptions(fn () => PlanFeature::catalog()->reject->built()->map(fn () => 'Coming soon')->all())
                ->helperText('Names and order are set in Plan features. "Coming soon" ones show greyed out on the start page until they are built.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->weight('bold'),
                TextColumn::make('code')->badge(),
                TextColumn::make('price_monthly_cents')->label('Price')
                    ->formatStateUsing(fn ($state, Plan $record) => $record->price_per_order_cents !== null
                        ? Money::format($record->price_per_order_cents, 'USD').' / order'
                        : Money::format($state, 'USD').' / month'),
                TextColumn::make('max_branches')->label('Branches')->placeholder('∞'),
                TextColumn::make('max_tables')->label('Tables')->placeholder('∞'),
                TextColumn::make('subscriptions_count')->label('Companies')->counts('subscriptions'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPlans::route('/')];
    }
}
