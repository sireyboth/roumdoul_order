<?php

namespace App\Filament\App\Resources\DailySales;

use App\Filament\App\Resources\DailySales\Pages\ListDailySales;
use App\Models\Branch;
use App\Models\DailyBranchSale;
use App\Services\Reports\CsvExport;
use App\Support\BranchScope;
use App\Support\Money;
use App\Support\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Sales per branch per business day (paid bills), plus spreadsheet exports. Read-only. */
class DailySalesResource extends Resource
{
    protected static ?string $model = DailyBranchSale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Daily sales';

    protected static ?string $modelLabel = 'day';

    protected static ?string $pluralModelLabel = 'daily sales';

    /** Managers see only their own branches; owners see all (BranchScope). */
    public static function getEloquentQuery(): Builder
    {
        return BranchScope::apply(parent::getEloquentQuery(), 'branch_id');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** Header button: pick dates (and a branch) and download one of the CSV exports. */
    public static function exportAction(string $type, string $label): Action
    {
        return Action::make('export_'.$type)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading("Export {$label}")
            ->modalDescription('A spreadsheet file (CSV) that opens in Excel, Khmer included.')
            ->modalSubmitActionLabel('Download')
            ->schema([
                DatePicker::make('from')->label('From business day')->required()->default(now()->subDays(6)->toDateString()),
                DatePicker::make('to')->label('To business day')->required()->default(now()->toDateString())->afterOrEqual('from'),
                Select::make('branch_id')
                    ->label('Branch')
                    ->placeholder('All branches')
                    ->options(fn () => BranchScope::branches(Branch::query())->orderBy('sort_order')->pluck('name', 'id')),
            ])
            ->action(fn (array $data) => (new CsvExport(Tenant::current(), BranchScope::ids()))->download(
                $type,
                substr((string) $data['from'], 0, 10),
                substr((string) $data['to'], 0, 10),
                filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : null,
            ));
    }

    public static function table(Table $table): Table
    {
        $money = fn (string $column) => fn (DailyBranchSale $record) => Money::format($record->{$column}, $record->currency);
        $sum = fn (string $column) => Sum::make()->formatStateUsing(fn ($state) => Money::format((int) $state, Tenant::currency()));

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('branch'))
            ->defaultSort('business_date', 'desc')
            ->columns([
                TextColumn::make('business_date')->label('Business day')->date('D d M Y')->sortable(),
                TextColumn::make('branch.name')->label('Branch')->visibleFrom('lg'),
                TextColumn::make('bills_count')->label('Bills')->numeric()->summarize(Sum::make())->visibleFrom('sm'),
                TextColumn::make('orders_count')->label('Orders')->numeric()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('net')->label('Net sales')->formatStateUsing($money('net'))->weight('bold')->alignEnd()->summarize($sum('net')),
                TextColumn::make('cash')->formatStateUsing($money('cash'))->alignEnd()->summarize($sum('cash'))->visibleFrom('md'),
                TextColumn::make('khqr')->label('KHQR')->formatStateUsing($money('khqr'))->alignEnd()->summarize($sum('khqr'))->visibleFrom('md'),
                TextColumn::make('card')->formatStateUsing($money('card'))->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('discounts')->formatStateUsing($money('discounts'))->alignEnd()->summarize($sum('discounts'))->visibleFrom('lg'),
                TextColumn::make('refunds')->formatStateUsing($money('refunds'))->alignEnd()->summarize($sum('refunds'))->visibleFrom('lg'),
                TextColumn::make('service_charge')->label('Service')->formatStateUsing($money('service_charge'))->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vat')->label('VAT')->formatStateUsing($money('vat'))->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cancelled_count')->label('Cancelled orders')->numeric()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('branch')->relationship('branch', 'name', fn (Builder $query) => BranchScope::branches($query)),
                Filter::make('dates')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('to')->label('To'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('business_date', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('business_date', '<=', $d))),
            ])
            ->emptyStateHeading('No sales yet')
            ->emptyStateDescription('Days appear here after the first bill is paid.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDailySales::route('/'),
        ];
    }
}
