<?php

namespace App\Filament\App\Resources\Shifts;

use App\Filament\App\Resources\Shifts\Pages\ListShifts;
use App\Filament\App\Resources\Shifts\Pages\ViewShift;
use App\Models\Shift;
use App\Services\Billing\ShiftService;
use App\Support\Money;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Cash drawer shifts, read-only. Cashiers open and close them on the cashier screen. */
class ShiftResource extends Resource
{
    protected static ?string $model = Shift::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    /** "$18.00 · 81,500៛" (a minus sign when the drawer is short) */
    public static function both(?int $usd, ?int $khr): string
    {
        if ($usd === null && $khr === null) {
            return '—';
        }

        return self::signed($usd ?? 0, 'USD').' · '.self::signed($khr ?? 0, 'KHR');
    }

    private static function signed(int $amount, string $currency): string
    {
        return ($amount < 0 ? '−' : '').Money::format(abs($amount), $currency);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['branch', 'openedBy', 'closedBy']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('opened_at')->label('Opened')->dateTime('d M H:i')->sortable(),
                TextColumn::make('closed_at')->label('Closed')->dateTime('d M H:i')->placeholder('Still open'),
                TextColumn::make('branch.name')->label('Branch'),
                TextColumn::make('openedBy.name')->label('Cashier')->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'open' ? 'warning' : 'gray'),
                TextColumn::make('expected')
                    ->label('Expected cash')
                    ->state(fn (Shift $record) => self::both($record->expected_cash_usd, $record->expected_cash_khr)),
                TextColumn::make('counted')
                    ->label('Counted')
                    ->state(fn (Shift $record) => self::both($record->counted_cash_usd, $record->counted_cash_khr)),
                TextColumn::make('difference')
                    ->label('Difference')
                    ->state(fn (Shift $record) => self::both($record->difference_usd, $record->difference_khr))
                    ->color(fn (Shift $record) => ($record->difference_usd ?? 0) < 0 || ($record->difference_khr ?? 0) < 0 ? 'danger' : null)
                    ->weight('bold'),
            ])
            ->filters([
                SelectFilter::make('status')->options(['open' => 'Open', 'closed' => 'Closed']),
                SelectFilter::make('branch')->relationship('branch', 'name', fn (Builder $query) => TenantScope::apply($query)),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $line = fn (string $key, string $label, string $usd, string $khr) => TextEntry::make($key)
            ->label($label)
            ->state(function (Shift $record) use ($usd, $khr) {
                $summary = ShiftService::summary($record);

                return self::both($summary[$usd], $summary[$khr]);
            });

        return $schema->components([
            Grid::make(2)->schema([
                Section::make('Shift')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('branch.name')->label('Branch'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('opened_at')->label('Opened')->dateTime('d M Y H:i'),
                        TextEntry::make('openedBy.name')->label('Opened by')->placeholder('—'),
                        TextEntry::make('closed_at')->label('Closed')->dateTime('d M Y H:i')->placeholder('Still open'),
                        TextEntry::make('closedBy.name')->label('Closed by')->placeholder('—'),
                        TextEntry::make('note')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Cash drawer (dollars · riel)')
                    ->schema([
                        $line('opening', 'Opening count', 'opening_usd', 'opening_khr'),
                        $line('received', 'Cash received', 'cash_received_usd', 'cash_received_khr'),
                        $line('change', 'Change given', 'change_given_usd', 'change_given_khr'),
                        $line('cash_in', 'Cash in', 'cash_in_usd', 'cash_in_khr'),
                        $line('cash_out', 'Cash out', 'cash_out_usd', 'cash_out_khr'),
                        $line('refunded', 'Cash refunded', 'refunded_usd', 'refunded_khr'),
                        $line('expected', 'Expected in drawer', 'expected_usd', 'expected_khr')->weight('bold'),
                        TextEntry::make('counted')
                            ->label('Counted')
                            ->state(fn (Shift $record) => self::both($record->counted_cash_usd, $record->counted_cash_khr)),
                        TextEntry::make('difference')
                            ->label('Difference')
                            ->state(fn (Shift $record) => self::both($record->difference_usd, $record->difference_khr))
                            ->weight('bold'),
                    ]),
            ]),
            Section::make('Takings by method')
                ->schema([
                    TextEntry::make('by_method')
                        ->hiddenLabel()
                        ->listWithLineBreaks()
                        ->state(function (Shift $record) {
                            $currency = $record->branch?->company?->currency ?? 'USD';
                            $rows = collect(ShiftService::summary($record)['by_method']);

                            return $rows->isEmpty()
                                ? ['No payments yet']
                                : $rows->map(fn (array $row, string $method) => strtoupper($method).': '.$row['count'].' payment(s), '.Money::format($row['amount'], $currency))->values()->all();
                        }),
                ]),
            Section::make('Cash in and out')
                ->schema([
                    RepeatableEntry::make('movements')
                        ->hiddenLabel()
                        ->placeholder('None')
                        ->columns(4)
                        ->schema([
                            TextEntry::make('created_at')->label('Time')->dateTime('H:i'),
                            TextEntry::make('type')->badge()->color(fn (string $state) => $state === 'in' ? 'success' : 'warning'),
                            TextEntry::make('amount')->state(fn ($record) => Money::format($record->amount, $record->currency)),
                            TextEntry::make('reason'),
                        ]),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShifts::route('/'),
            'view' => ViewShift::route('/{record}'),
        ];
    }
}
