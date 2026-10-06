<?php

namespace App\Filament\App\Widgets;

use App\Services\Reports\SalesReport;
use App\Support\Money;
use App\Support\Tenant;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/** Net sales per business day, last 14 days. */
class SalesChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Sales, last 14 days';

    protected ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $company = Tenant::current();

        if (! $company) {
            return ['datasets' => [], 'labels' => []];
        }

        $report = new SalesReport($company);
        $today = $report->today();
        $days = $report->days(Carbon::parse($today)->subDays(13)->toDateString(), $today);

        return [
            'datasets' => [[
                'label' => 'Net sales ('.$company->currency.')',
                'data' => $days->map(fn ($d) => Money::fromMinor($d['net'], $company->currency))->values()->all(),
                'backgroundColor' => '#0f7a5c',
                'borderColor' => '#0f7a5c',
            ]],
            'labels' => $days->keys()->map(fn ($d) => Carbon::parse($d)->format('D d'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
