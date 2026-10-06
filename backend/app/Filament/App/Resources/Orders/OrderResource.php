<?php

namespace App\Filament\App\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
use App\Filament\App\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Support\Money;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Every order, for owners and managers. Orders are changed on the staff screens; here you can only cancel. */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel order')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Order $record) => $record->status->canMoveTo(OrderStatus::Cancelled))
            ->schema([
                Textarea::make('reason')->label('Reason')->required()->maxLength(255)->placeholder('e.g. Customer changed their mind'),
            ])
            ->modalDescription('The kitchen stops preparing it. The reason is saved in the audit log.')
            ->action(fn (Order $record, array $data) => $record->moveTo(OrderStatus::Cancelled, Auth::user(), $data['reason']));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['branch', 'table']))
            ->defaultSort('id', 'desc')
            ->poll('15s')
            ->columns([
                TextColumn::make('number')->label('#')->weight('bold')->prefix('#'),
                TextColumn::make('created_at')->label('Time')->dateTime('d M H:i')->sortable(),
                TextColumn::make('branch.name')->label('Branch'),
                TextColumn::make('table.name')->label('Table')->placeholder('—'),
                TextColumn::make('status')->badge(),
                TextColumn::make('source')->badge()->color('gray'),
                TextColumn::make('subtotal')
                    ->label('Amount')
                    ->formatStateUsing(fn (Order $record) => Money::format($record->subtotal, $record->currency))
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('status')->options(OrderStatus::class)->multiple(),
                SelectFilter::make('branch')->relationship('branch', 'name', fn (Builder $query) => TenantScope::apply($query)),
                Filter::make('business_date')
                    ->schema([DatePicker::make('date')->label('Business day')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['date'] ?? null, fn ($q, $date) => $q->whereDate('business_date', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                self::cancelAction(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                Section::make('Order')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('number')->prefix('#'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('branch.name')->label('Branch'),
                        TextEntry::make('table.name')->label('Table')->placeholder('—'),
                        TextEntry::make('business_date')->date('d M Y'),
                        TextEntry::make('created_at')->label('Placed')->dateTime('d M Y H:i'),
                        TextEntry::make('ready_at')->label('Ready')->dateTime('H:i')->placeholder('—'),
                        TextEntry::make('served_at')->label('Served')->dateTime('H:i')->placeholder('—'),
                        TextEntry::make('cancel_reason')->label('Cancel reason')->placeholder('—')->visible(fn (Order $record) => $record->cancel_reason !== null),
                        TextEntry::make('note')->placeholder('—'),
                    ]),
                Section::make('Items')
                    ->columnSpan(2)
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->columns(4)
                            ->schema([
                                TextEntry::make('name_km')->label('Item')->weight('bold')
                                    ->helperText(fn ($record) => $record->name_en),
                                TextEntry::make('options')->label('Choices')
                                    ->state(fn ($record) => collect($record->options ?? [])->pluck('name_en')->implode(', ') ?: '—'),
                                TextEntry::make('quantity')->label('Qty')->prefix('× '),
                                TextEntry::make('line_total')->label('Amount')
                                    ->state(fn ($record) => Money::format($record->line_total, $record->order->currency)),
                            ]),
                        TextEntry::make('subtotal')
                            ->label('Subtotal')
                            ->state(fn (Order $record) => Money::format($record->subtotal, $record->currency))
                            ->weight('bold'),
                    ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
