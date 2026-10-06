<?php

namespace App\Filament\App\Resources\DailySales\Pages;

use App\Filament\App\Resources\DailySales\DailySalesResource;
use App\Services\Reports\CsvExport;
use Filament\Resources\Pages\ListRecords;

class ListDailySales extends ListRecords
{
    protected static string $resource = DailySalesResource::class;

    protected function getHeaderActions(): array
    {
        return collect(CsvExport::TYPES)
            ->map(fn (string $label, string $type) => DailySalesResource::exportAction($type, $label))
            ->values()
            ->all();
    }
}
