<?php

namespace App\Filament\App\Resources\Branches\RelationManagers;

use App\Models\BranchMenuItem;
use App\Models\Category;
use App\Support\Money;
use App\Support\Tenant;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What this branch sells: switch items off, set a branch-only price, or mark
 * sold out. Every change bumps the branch menu version, so customers see it
 * on their next load.
 */
class MenuAvailabilityRelationManager extends RelationManager
{
    protected static string $relationship = 'menuItems';

    protected static ?string $title = 'Menu in this branch';

    public function table(Table $table): Table
    {
        $currency = Tenant::currency();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('menuItem.category')
                ->whereHas('menuItem'))
            ->columns([
                TextColumn::make('menuItem.name_en')
                    ->label('Item')
                    ->description(fn (BranchMenuItem $record) => $record->menuItem?->name_km)
                    ->searchable(),
                TextColumn::make('menuItem.category.name_en')->label('Category')->visibleFrom('md'),
                TextColumn::make('menuItem.price')
                    ->label('Company price')
                    ->visibleFrom('md')
                    ->formatStateUsing(fn ($state) => Money::format($state, $currency)),
                TextInputColumn::make('price')
                    ->label('Branch price')
                    ->placeholder('Same')
                    ->type('number')
                    ->step($currency === 'KHR' ? 100 : 0.01)
                    ->state(fn (BranchMenuItem $record) => Money::fromMinor($record->price, $currency))
                    ->rules(['nullable', 'numeric', 'min:0'])
                    ->updateStateUsing(function (BranchMenuItem $record, $state) use ($currency) {
                        $record->update(['price' => Money::toMinor($state, $currency)]);

                        return $state;
                    }),
                ToggleColumn::make('is_available')->label('Sold here'),
                TextColumn::make('sold_out_until')
                    ->label('Sold out')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (BranchMenuItem $record) => $record->isSoldOut() ? 'until '.$record->sold_out_until->format('H:i') : null)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(fn () => Category::query()->where('company_id', Tenant::id())->orderBy('sort_order')->pluck('name_en', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('menuItem', fn (Builder $q) => $q->where('category_id', $data['value']))
                        : $query),
                TernaryFilter::make('is_available')->label('Sold here'),
            ])
            ->recordActions([
                Action::make('soldOut')
                    ->label('Sold out today')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (BranchMenuItem $record) => ! $record->isSoldOut())
                    ->action(fn (BranchMenuItem $record) => $record->markSoldOut()),
                Action::make('available')
                    ->label('Back in stock')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (BranchMenuItem $record) => $record->isSoldOut())
                    ->action(fn (BranchMenuItem $record) => $record->markAvailable()),
            ]);
    }
}
