<?php

namespace App\Filament\App\Resources\MenuItems\Pages;

use App\Filament\App\Resources\MenuItems\MenuItemResource;
use Filament\Actions\CreateAction;
use App\Models\MenuItem;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListMenuItems extends ListRecords
{
    protected static string $resource = MenuItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->modalWidth(Width::SixExtraLarge)
                // Option groups are attached after the item is saved; refresh the menu cache key again.
                ->after(fn (MenuItem $record) => $record->bumpMenuVersion()),
        ];
    }
}
