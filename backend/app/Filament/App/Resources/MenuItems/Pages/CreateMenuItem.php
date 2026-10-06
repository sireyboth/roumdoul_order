<?php

namespace App\Filament\App\Resources\MenuItems\Pages;

use App\Filament\App\Resources\MenuItems\MenuItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMenuItem extends CreateRecord
{
    protected static string $resource = MenuItemResource::class;

    protected function afterCreate(): void
    {
        // Option groups are attached after the item is saved; refresh the menu cache key again.
        $this->record->bumpMenuVersion();
    }
}
