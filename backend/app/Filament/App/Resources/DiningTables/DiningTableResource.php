<?php

namespace App\Filament\App\Resources\DiningTables;

use App\Filament\App\Resources\DiningTables\Pages\ListDiningTables;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\TableArea;
use App\Support\BranchScope;
use App\Support\QrCode;
use App\Support\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class DiningTableResource extends Resource
{
    protected static ?string $model = DiningTable::class;

    protected static ?string $modelLabel = 'table';

    protected static ?string $navigationLabel = 'Tables & QR codes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    /** Managers see only their own branches; owners see all (BranchScope). */
    public static function getEloquentQuery(): Builder
    {
        return BranchScope::apply(parent::getEloquentQuery(), 'branch_id');
    }

    public static function canCreate(): bool
    {
        return ! (Tenant::current()?->hasReachedLimit('tables') ?? true);
    }

    /** Branch + area fields, shared by the single-table form and "Add many tables". */
    public static function branchAndAreaFields(): array
    {
        return [
            Select::make('branch_id')
                ->label('Branch')
                ->relationship('branch', 'name', fn (Builder $query) => BranchScope::branches($query))
                ->default(fn () => BranchScope::branches(Branch::query())->orderBy('sort_order')->value('id'))
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('table_area_id', null)),
            Select::make('table_area_id')
                ->label('Area')
                ->placeholder('No area')
                ->options(fn (Get $get) => TableArea::query()
                    ->where('company_id', Tenant::id())
                    ->where('branch_id', $get('branch_id'))
                    ->orderBy('sort_order')
                    ->pluck('name', 'id'))
                ->helperText('Add areas (Indoor, Terrace...) on the branch page.'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            ...self::branchAndAreaFields(),
            TextInput::make('name')
                ->label('Table name')
                ->placeholder('T1')
                ->required()
                ->maxLength(40)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('branch_id', $get('branch_id'))),
            TextInput::make('seats')->numeric()->integer()->minValue(1)->maxValue(99),
            Toggle::make('is_active')->label('Accepting orders')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['branch', 'area']))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('branch.name')->label('Branch')->visibleFrom('md'),
                TextColumn::make('area.name')->label('Area')->placeholder('—'),
                TextColumn::make('seats')->placeholder('—')->visibleFrom('sm'),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->filters([
                SelectFilter::make('branch')
                    ->relationship('branch', 'name', fn (Builder $query) => BranchScope::branches($query)),
            ])
            ->recordActions([
                Action::make('qr')
                    ->label('QR')
                    ->icon(Heroicon::OutlinedQrCode)
                    ->modalHeading(fn (DiningTable $record) => 'Table '.$record->name)
                    ->modalWidth('sm')
                    ->modalContent(fn (DiningTable $record) => new HtmlString(
                        '<div style="display:grid;justify-items:center;gap:12px;text-align:center">'
                        .'<div style="background:#fff;padding:12px;border-radius:8px">'.QrCode::svg($record->customerUrl()).'</div>'
                        .'<div style="font-size:12px;word-break:break-all;opacity:.7">'.e($record->customerUrl()).'</div>'
                        .'</div>'
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                ActionGroup::make([
                    Action::make('open')
                        ->label('Open customer menu')
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(fn (DiningTable $record) => $record->customerUrl(), shouldOpenInNewTab: true),
                    Action::make('download')
                        ->label('Download QR (SVG)')
                        ->icon(Heroicon::OutlinedArrowDownTray)
                        ->action(fn (DiningTable $record) => response()->streamDownload(
                            function () use ($record) {
                                echo QrCode::svg($record->customerUrl(), 800);
                            },
                            'table-'.str($record->branch?->code ?? $record->branch_id)->slug().'-'.str($record->name)->slug().'.svg',
                            ['Content-Type' => 'image/svg+xml'],
                        )),
                    Action::make('newQr')
                        ->label('Make new QR code')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('The old printed QR code for this table will stop working straight away. Use this if a QR code was copied or shared.')
                        ->action(function (DiningTable $record) {
                            $record->regenerateToken();

                            Notification::make()->title('New QR code ready. Print and replace the old one.')->success()->send();
                        }),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiningTables::route('/'),
        ];
    }
}
