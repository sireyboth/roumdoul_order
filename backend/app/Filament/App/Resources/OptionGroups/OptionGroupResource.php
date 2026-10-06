<?php

namespace App\Filament\App\Resources\OptionGroups;

use App\Filament\App\Resources\OptionGroups\Pages\ListOptionGroups;
use App\Models\OptionGroup;
use App\Support\Money;
use App\Support\Tenant;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class OptionGroupResource extends Resource
{
    protected static ?string $model = OptionGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Menu';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function form(Schema $schema): Schema
    {
        $currency = Tenant::currency();

        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name_km')->label('Name (Khmer)')->placeholder('កម្រិតស្ករ')->required()->maxLength(80),
                TextInput::make('name_en')->label('Name (English)')->placeholder('Sugar level')->required()->maxLength(80),
                TextInput::make('min_select')
                    ->label('Customer must pick at least')
                    ->numeric()->integer()->minValue(0)->maxValue(10)->default(1)->required()
                    ->helperText('0 = optional, 1 = required'),
                TextInput::make('max_select')
                    ->label('Customer can pick at most')
                    ->numeric()->integer()->maxValue(20)->default(1)->required()
                    ->minValue(fn (Get $get) => max(1, (int) $get('min_select'))),
            ]),
            Repeater::make('options')
                ->relationship()
                ->orderColumn('sort_order')
                ->addActionLabel('Add choice')
                ->minItems(1)
                ->columns(4)
                ->itemLabel(fn (array $state) => $state['name_en'] ?? null)
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => [...$data, 'company_id' => Tenant::id()])
                ->schema([
                    TextInput::make('name_km')->label('Khmer')->required()->maxLength(80),
                    TextInput::make('name_en')->label('English')->required()->maxLength(80),
                    TextInput::make('price_delta')
                        ->label('Extra price')
                        ->prefix($currency === 'KHR' ? '៛' : '$')
                        ->numeric()
                        ->default(0)
                        ->step($currency === 'KHR' ? 100 : 0.01)
                        ->formatStateUsing(fn ($state) => Money::fromMinor($state, $currency))
                        ->dehydrateStateUsing(fn ($state) => Money::toMinor($state, $currency) ?? 0),
                    Grid::make(1)->schema([
                        Toggle::make('is_default')->label('Pre-selected'),
                        Toggle::make('is_active')->label('Available')->default(true),
                    ])->columnSpan(1),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name_km')->label('Group')->description(fn (OptionGroup $record) => $record->name_en)->weight('bold'),
                TextColumn::make('rule')
                    ->label('Rule')
                    ->state(fn (OptionGroup $record) => match (true) {
                        $record->min_select === 1 && $record->max_select === 1 => 'Pick 1 (required)',
                        $record->min_select === 0 && $record->max_select === 1 => 'Pick 0 or 1',
                        default => "Pick {$record->min_select} to {$record->max_select}",
                    }),
                TextColumn::make('options_count')->label('Choices')->counts('options')->badge(),
                TextColumn::make('menu_items_count')->label('Used by items')->counts('menuItems'),
            ])
            ->recordActions([
                EditAction::make()->after(fn (OptionGroup $record) => $record->bumpMenuVersion()),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOptionGroups::route('/'),
        ];
    }
}
