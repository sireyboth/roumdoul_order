<?php

namespace App\Filament\Admin\Resources\Companies;

use App\Enums\CompanyStatus;
use App\Filament\Admin\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\Plan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Every restaurant on the platform. */
class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $navigationLabel = 'Restaurants';

    protected static ?string $modelLabel = 'restaurant';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name')->required(),
                TextInput::make('slug')->required()->alphaDash()->unique(ignoreRecord: true),
                Select::make('status')->options(CompanyStatus::class)->required(),
                DateTimePicker::make('trial_ends_at'),
                TextInput::make('phone'),
                TextInput::make('email')->email(),
                TextInput::make('coreos_company_id')->label('coreos company ID')->numeric(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('subscription.plan'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold')->description(fn (Company $record) => $record->slug),
                TextColumn::make('status')->badge(),
                TextColumn::make('subscription.plan.name')->label('Plan')->placeholder('—'),
                TextColumn::make('branches_count')->label('Branches')->counts('branches'),
                TextColumn::make('dining_tables_count')->label('Tables')->counts('diningTables'),
                TextColumn::make('trial_ends_at')->dateTime('d M Y')->placeholder('—'),
                TextColumn::make('created_at')->since()->label('Joined'),
            ])
            ->filters([
                SelectFilter::make('status')->options(CompanyStatus::class),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCompanies::route('/')];
    }
}
