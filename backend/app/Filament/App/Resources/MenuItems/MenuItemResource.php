<?php

namespace App\Filament\App\Resources\MenuItems;

use App\Enums\Station;
use App\Filament\App\Resources\MenuItems\Pages\CreateMenuItem;
use App\Filament\App\Resources\MenuItems\Pages\EditMenuItem;
use App\Filament\App\Resources\MenuItems\Pages\ListMenuItems;
use App\Models\MenuItem;
use App\Support\Money;
use App\Support\Tenant;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MenuItemResource extends Resource
{
    protected static ?string $model = MenuItem::class;

    protected static ?string $navigationLabel = 'Menu items';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Menu';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function form(Schema $schema): Schema
    {
        $currency = Tenant::currency();

        return $schema->components([
            Grid::make(3)->schema([
                Section::make('Item')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name_km')->label('Name (Khmer)')->required()->maxLength(120),
                        TextInput::make('name_en')->label('Name (English)')->required()->maxLength(120),
                        Textarea::make('description_km')->label('Description (Khmer)')->rows(2),
                        Textarea::make('description_en')->label('Description (English)')->rows(2),
                        TextInput::make('name_zh')->label('Name (Chinese)')->maxLength(120),
                        TextInput::make('sku')->label('SKU / code')->maxLength(40),
                    ]),
                Section::make('Price & settings')
                    ->columnSpan(1)
                    ->schema([
                        Select::make('category_id')
                            ->label('Category')
                            ->relationship('category', 'name_en', fn (Builder $query) => TenantScope::apply($query)->orderBy('sort_order'))
                            ->required()
                            ->preload(),
                        TextInput::make('price')
                            ->label('Price')
                            ->prefix($currency === 'KHR' ? '៛' : '$')
                            ->numeric()
                            ->minValue(0)
                            ->step($currency === 'KHR' ? 100 : 0.01)
                            ->required()
                            ->formatStateUsing(fn ($state) => Money::fromMinor($state, $currency))
                            ->dehydrateStateUsing(fn ($state) => Money::toMinor($state, $currency)),
                        Select::make('station')
                            ->label('Ticket goes to')
                            ->options(Station::class)
                            ->default(Station::Kitchen)
                            ->required(),
                        Toggle::make('is_active')->label('Show on menu')->default(true),
                    ]),
                Section::make('Options')
                    ->columnSpan(2)
                    ->description('Choices the customer makes, like size or sugar level. Create them under Menu > Option groups.')
                    ->schema([
                        Select::make('optionGroups')
                            ->label('Option groups')
                            ->multiple()
                            ->relationship('optionGroups', 'name_en', fn (Builder $query) => TenantScope::apply($query))
                            ->preload(),
                    ]),
                Section::make('Photo')
                    ->columnSpan(1)
                    ->schema([
                        FileUpload::make('image_path')
                            ->hiddenLabel()
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('menu')
                            ->maxSize(3072),
                    ]),
                Section::make('Goes well with')
                    ->columnSpan(2)
                    ->schema([
                        Select::make('suggestions')
                            ->label('Suggest with (Goes well with)')
                            ->helperText('After a customer adds this item, these are suggested in a small pop-up. Pick up to 3, e.g. a pastry for a coffee.')
                            ->multiple()
                            ->maxItems(3)
                            ->relationship(
                                'suggestions',
                                'name_en',
                                fn (Builder $query, ?Model $record) => TenantScope::apply($query)
                                    ->when($record, fn (Builder $q) => $q->whereKeyNot($record->getKey()))
                                    ->orderBy('menu_items.sort_order'),
                            )
                            ->saveRelationshipsUsing(function (Model $record, $state) {
                                $ids = collect($state ?? [])->map(fn ($id) => (int) $id)->unique()->values();
                                // Only items of this restaurant, never the item itself.
                                $allowed = TenantScope::apply(MenuItem::query())
                                    ->whereKey($ids)
                                    ->whereKeyNot($record->getKey())
                                    ->pluck('id')
                                    ->all();
                                $record->suggestions()->sync(
                                    $ids->filter(fn ($id) => in_array($id, $allowed))
                                        ->take(3)
                                        ->values()
                                        ->mapWithKeys(fn ($id, $i) => [$id => ['sort_order' => $i]])
                                        ->all(),
                                );
                            })
                            ->preload(),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $currency = Tenant::currency();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                ImageColumn::make('image_path')->label('')->disk('public')->square()->size(44),
                TextColumn::make('name_km')
                    ->label('Item')
                    ->description(fn (MenuItem $record) => $record->name_en)
                    ->searchable(['name_km', 'name_en', 'sku'])
                    ->weight('bold'),
                TextColumn::make('category.name_en')->label('Category'),
                TextColumn::make('price')
                    ->formatStateUsing(fn ($state) => Money::format($state, $currency))
                    ->sortable(),
                TextColumn::make('station')->badge(),
                ToggleColumn::make('is_active')->label('Shown'),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->relationship('category', 'name_en', fn (Builder $query) => TenantScope::apply($query)),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenuItems::route('/'),
            'create' => CreateMenuItem::route('/create'),
            'edit' => EditMenuItem::route('/{record}/edit'),
        ];
    }
}
