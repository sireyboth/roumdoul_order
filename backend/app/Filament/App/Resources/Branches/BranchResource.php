<?php

namespace App\Filament\App\Resources\Branches;

use App\Filament\App\Resources\Branches\Pages\ListBranches;
use App\Filament\App\Resources\Branches\Pages\ViewBranch;
use App\Filament\App\Resources\Branches\RelationManagers\AreasRelationManager;
use App\Filament\App\Resources\Branches\RelationManagers\MenuAvailabilityRelationManager;
use App\Models\Branch;
use App\Services\Ordering\LocationCheck;
use App\Support\BranchScope;
use App\Support\MapLink;
use App\Support\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\Width;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    /** Managers see only their own branches; owners see all (BranchScope). */
    public static function getEloquentQuery(): Builder
    {
        return BranchScope::apply(parent::getEloquentQuery(), 'id');
    }

    public static function canCreate(): bool
    {
        return BranchScope::seesAllBranches() && ! (Tenant::current()?->hasReachedLimit('branches') ?? true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Branch')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(120),
                    TextInput::make('code')
                        ->maxLength(20)
                        ->helperText('Short code for reports, e.g. BKK1.')
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', Tenant::id())),
                    TextInput::make('phone')->tel()->maxLength(30),
                    TextInput::make('address')->maxLength(255),
                    TimePicker::make('day_ends_at')
                        ->label('Business day ends at')
                        ->seconds(false)
                        // A clock time in the branch, not a moment: never shift it by time zone.
                        ->timezone(config('app.timezone'))
                        ->default('04:00')
                        ->helperText('Sales after midnight and before this time count toward the previous day.'),
                    Toggle::make('is_active')->label('Open for orders')->default(true),
                ]),
            Section::make('Printing')
                ->description('Receipts and kitchen tickets print from the staff screens on an 80 mm printer.')
                ->columns(2)
                ->schema([
                    Textarea::make('receipt_header')
                        ->label('Receipt header')
                        ->rows(3)
                        ->maxLength(500)
                        ->placeholder('e.g. VAT TIN K001-123456789, Wi-Fi: demo-cafe'),
                    Textarea::make('receipt_footer')
                        ->label('Receipt footer')
                        ->rows(3)
                        ->maxLength(500)
                        ->placeholder('e.g. សូមអរគុណ! Thank you, see you again!'),
                    Toggle::make('auto_print_kitchen')
                        ->label('Print kitchen tickets automatically')
                        ->helperText('The kitchen and bar screens print a ticket for each new order. For printing without a dialog, start Chrome with --kiosk-printing on that device.'),
                ]),
            Section::make('Location: only take orders from inside the shop')
                ->description('Stops orders from someone who took a photo of the QR code home. The customer\'s phone shares its location once, and the order is only accepted near this point. Staff do nothing.')
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextInput::make('map_link')
                        ->label('Paste the shop\'s Google Maps link')
                        ->placeholder('https://maps.app.goo.gl/... or 11.5564, 104.9282')
                        ->helperText('In Google Maps: find the shop → Share → Copy link, then paste here. Or, standing in the shop, tap "Use my current location". The numbers below fill in by themselves.')
                        ->hintAction(
                            Action::make('useMyLocation')
                                ->label('Use my current location')
                                ->icon(Heroicon::OutlinedMapPin)
                                // Runs in the browser: the device's own location fills the two fields.
                                ->alpineClickHandler('window.roUseMyLocation($set)'),
                        )
                        ->dehydrated(false)
                        ->live(debounce: 600)
                        ->afterStateUpdated(function (?string $state, Set $set) {
                            if ($point = MapLink::coordinates($state)) {
                                $set('latitude', $point['lat']);
                                $set('longitude', $point['lng']);
                            }
                        })
                        ->columnSpanFull(),
                    TextInput::make('latitude')
                        ->numeric()
                        ->minValue(-90)
                        ->maxValue(90)
                        ->live(onBlur: true)
                        ->required(fn (Get $get) => (bool) $get('require_location')),
                    TextInput::make('longitude')
                        ->numeric()
                        ->minValue(-180)
                        ->maxValue(180)
                        ->live(onBlur: true)
                        ->required(fn (Get $get) => (bool) $get('require_location'))
                        ->hintAction(
                            Action::make('checkOnMap')
                                ->label('Check on map')
                                ->icon(Heroicon::OutlinedMapPin)
                                ->visible(fn (Get $get) => filled($get('latitude')) && filled($get('longitude')))
                                ->url(fn (Get $get) => 'https://www.google.com/maps?q='.$get('latitude').','.$get('longitude'), shouldOpenInNewTab: true),
                        ),
                    Toggle::make('require_location')
                        ->label('Only accept QR orders from inside the shop')
                        ->helperText('Customers far away cannot order or call a waiter. Waiters can still type orders on the staff screen.')
                        ->live(),
                    TextInput::make('order_radius_m')
                        ->label('Allowed distance')
                        ->numeric()
                        ->integer()
                        ->minValue(30)
                        ->maxValue(2000)
                        ->default(150)
                        ->suffix('metres')
                        ->helperText('150 m suits most shops. Phone GPS indoors can be off by 50 m or more, so do not go much lower.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('code')->badge()->placeholder('—')->visibleFrom('sm'),
                TextColumn::make('dining_tables_count')->label('Tables')->counts('diningTables'),
                TextColumn::make('address')->limit(40)->placeholder('—')->toggleable()->visibleFrom('lg'),
                ToggleColumn::make('is_active')->label('Open'),
            ])
            // Tapping a row edits the branch in a modal; areas and the branch menu have their own screen.
            ->recordUrl(null)
            ->recordAction('edit')
            ->recordActions([
                EditAction::make()->modalWidth(Width::FourExtraLarge),
                Action::make('areasMenu')
                    ->label('Areas & menu')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->url(fn (Branch $record) => self::getUrl('view', ['record' => $record])),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->columns(['default' => 2, 'lg' => 5])
                ->schema([
                    TextEntry::make('code')->badge()->placeholder('—'),
                    TextEntry::make('phone')->placeholder('—'),
                    TextEntry::make('address')->placeholder('—'),
                    TextEntry::make('day_ends_at')->label('Business day ends')->time('H:i'),
                    IconEntry::make('is_active')->label('Open for orders')->boolean(),
                    TextEntry::make('require_location')
                        ->label('QR orders only from inside')
                        ->state(fn (Branch $record) => LocationCheck::required($record) ? "On, within {$record->order_radius_m} m" : 'Off')
                        ->badge()
                        ->color(fn (string $state) => $state === 'Off' ? 'gray' : 'success'),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            AreasRelationManager::class,
            MenuAvailabilityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBranches::route('/'),
            'view' => ViewBranch::route('/{record}'),
        ];
    }
}
