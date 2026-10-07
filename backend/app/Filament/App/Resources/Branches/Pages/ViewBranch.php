<?php

namespace App\Filament\App\Resources\Branches\Pages;

use App\Filament\App\Resources\Branches\BranchResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

/** A branch's table areas and its menu (availability, branch prices, sold out). Editing the branch itself is a modal. */
class ViewBranch extends ViewRecord
{
    protected static string $resource = BranchResource::class;

    public function getTitle(): string
    {
        return $this->record->name.' · Areas & menu';
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Edit branch')->modalWidth(Width::FourExtraLarge),
            DeleteAction::make(),
        ];
    }
}
