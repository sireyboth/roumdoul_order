<?php

namespace App\Filament\App\Resources\Branches;

use App\Filament\App\Resources\Branches\Pages\CreateBranch;
use App\Filament\App\Resources\Branches\Pages\EditBranch;
use App\Filament\App\Resources\Branches\Pages\ListBranches;
use App\Filament\App\Resources\Branches\RelationManagers\AreasRelationManager;
use App\Filament\App\Resources\Branches\RelationManagers\MenuAvailabilityRelationManager;
use App\Models\Branch;
use App\Support\Tenant;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canCreate(): bool
    {
        return ! (Tenant::current()?->hasReachedLimit('branches') ?? true);
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
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('code')->badge()->placeholder('—'),
                TextColumn::make('dining_tables_count')->label('Tables')->counts('diningTables'),
                TextColumn::make('address')->limit(40)->placeholder('—')->toggleable(),
                ToggleColumn::make('is_active')->label('Open'),
            ])
            ->recordActions([
                EditAction::make(),
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
            'create' => CreateBranch::route('/create'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }
}
