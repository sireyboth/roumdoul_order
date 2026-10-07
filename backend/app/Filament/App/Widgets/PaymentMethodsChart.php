<?php

namespace App\Filament\App\Widgets;

use App\Services\Reports\SalesReport;
use App\Support\BranchScope;
use App\Support\Money;
use App\Support\Tenant;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/** How customers paid in the last 7 days. */
class PaymentMethodsChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Payment methods, last 7 days';

    protected ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $company = Tenant::current();

        if (! $company) {
            return ['datasets' => [], 'labels' => []];
        }

        $report = new SalesReport($company, BranchScope::ids());
        $today = $report->today();
        $total = $report->total(Carbon::parse($today)->subDays(6)->toDateString(), $today);
        $methods = ['cash' => 'Cash', 'khqr' => 'KHQR', 'card' => 'Card', 'other' => 'Other'];

        return [
            'datasets' => [[
                'label' => $company->currency,
                'data' => collect($methods)->keys()->map(fn ($m) => Money::fromMinor($total[$m], $company->currency))->all(),
                'backgroundColor' => ['#0f7a5c', '#1d4ed8', '#d97706', '#6b7280'],
            ]],
            'labels' => array_values($methods),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
