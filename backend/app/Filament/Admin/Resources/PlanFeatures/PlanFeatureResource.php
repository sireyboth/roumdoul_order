<?php

namespace App\Filament\Admin\Resources\PlanFeatures;

use App\Filament\Admin\Resources\PlanFeatures\Pages\ListPlanFeatures;
use App\Models\Plan;
use App\Models\PlanFeature;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Names and order of the features on the start page price cards. The list itself and whether
 * each feature is built come from App\Enums\Feature; which plan has which is ticked in Plans.
 */
class PlanFeatureResource extends Resource
{
    protected static ?string $model = PlanFeature::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->known();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->columnSpanFull()
                ->helperText('Shown on the start page price cards.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        // key => names of the plans that include it, read once per page.
        $plans = Plan::query()->orderBy('sort_order')->get(['name', 'features']);
        $plansWith = fn (PlanFeature $record) => $plans
            ->filter(fn (Plan $plan) => $plan->hasFeature($record->key))
            ->pluck('name')->all();

        return $table
            ->description('The list and the Built / Coming soon status are set by the developers in the code, in the same update that finishes a feature. Here you set the name and order; tick features on plans in Plans.')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->weight('bold')->wrap(),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (PlanFeature $record) => $record->built() ? 'Built' : 'Coming soon')
                    ->color(fn (string $state) => $state === 'Built' ? 'success' : 'gray'),
                TextColumn::make('plans')->label('In plans')->badge()
                    ->state($plansWith)
                    ->placeholder('Not in any plan yet')
                    ->visibleFrom('md'),
                TextColumn::make('key')->badge()->color('gray')->visibleFrom('lg'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPlanFeatures::route('/')];
    }
}
