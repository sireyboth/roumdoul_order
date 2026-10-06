<?php

namespace App\Filament\App\Resources\Categories;

use App\Filament\App\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Menu';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name_km')->label('Name (Khmer)')->required()->maxLength(120),
            TextInput::make('name_en')->label('Name (English)')->required()->maxLength(120),
            TextInput::make('name_zh')->label('Name (Chinese)')->maxLength(120),
            FileUpload::make('image_path')->label('Image')->image()->disk('public')->directory('categories')->maxSize(2048),
            Toggle::make('is_active')->label('Show on menu')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                ImageColumn::make('image_path')->label('')->disk('public')->square()->size(40),
                TextColumn::make('name_km')->label('Khmer')->searchable()->weight('bold'),
                TextColumn::make('name_en')->label('English')->searchable(),
                TextColumn::make('menu_items_count')->label('Items')->counts('menuItems')->badge(),
                ToggleColumn::make('is_active')->label('Shown'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->disabled(fn (Category $record) => $record->menuItems()->exists())
                    ->tooltip(fn (Category $record) => $record->menuItems()->exists() ? 'Move or delete its items first' : null),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
        ];
    }
}
