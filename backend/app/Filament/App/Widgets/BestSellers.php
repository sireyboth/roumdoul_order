<?php

namespace App\Filament\App\Widgets;

use App\Models\DailyItemSale;
use App\Services\Reports\SalesReport;
use App\Support\Money;
use App\Support\Tenant;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;

/** Most sold items in the last 7 days (paid bills). */
class BestSellers extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Best sellers, last 7 days';

    public function table(Table $table): Table
    {
        $company = Tenant::current();
        $today = $company ? (new SalesReport($company))->today() : now()->toDateString();

        return $table
            ->query(
                DailyItemSale::query()
                    // Widgets are not scoped by Filament tenancy: always filter by company here.
                    ->where('company_id', $company?->id ?? 0)
                    ->whereDate('business_date', '>=', Carbon::parse($today)->subDays(6)->toDateString())
                    ->whereDate('business_date', '<=', $today)
                    ->selectRaw('MIN(id) as id, menu_item_id, MAX(name_en) as name_en, MAX(name_km) as name_km, SUM(quantity) as quantity, SUM(amount) as amount')
                    ->groupBy('menu_item_id')
            )
            ->defaultSort('quantity', 'desc')
            ->paginated([10])
            ->columns([
                TextColumn::make('name_en')->label('Item')->description(fn ($record) => $record->name_km)->weight('bold'),
                TextColumn::make('quantity')->label('Sold')->numeric()->sortable(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->state(fn ($record) => Money::format((int) $record->amount, $company?->currency ?? 'USD'))
                    ->alignEnd(),
            ])
            ->emptyStateHeading('No sales yet in the last 7 days');
    }
}
