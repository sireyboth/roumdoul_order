<?php

namespace App\Filament\App\Widgets;

use App\Models\Order;
use App\Models\TableSession;
use App\Services\Reports\SalesReport;
use App\Support\BranchScope;
use App\Support\Money;
use App\Support\Tenant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/** Today and the last 7 days at a glance. Paid bills only; refreshed after every payment. */
class SalesOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $company = Tenant::current();

        if (! $company) {
            return [];
        }

        $report = new SalesReport($company, BranchScope::ids());
        $today = $report->today();
        $week = $report->days(Carbon::parse($today)->subDays(6)->toDateString(), $today);
        $before = $report->total(Carbon::parse($today)->subDays(13)->toDateString(), Carbon::parse($today)->subDays(7)->toDateString());
        $now = $week[$today];
        $weekNet = (int) $week->sum('net');
        $money = fn (int $minor) => Money::format($minor, $company->currency);

        // Live, not from the report copy: tables with an open visit and what they have ordered so far.
        $open = BranchScope::apply(TableSession::query()->where('company_id', $company->id))->where('status', '!=', 'closed')->pluck('id');
        $unpaid = (int) Order::query()->whereIn('table_session_id', $open)->where('status', '!=', 'cancelled')->sum('subtotal');

        $change = $before['net'] > 0 ? (int) round(($weekNet - $before['net']) * 100 / $before['net']) : null;

        return [
            Stat::make('Sales today', $money($now['net']))
                ->description($now['bills_count'].' bills'.($now['bills_count'] ? ' · average '.$money(intdiv($now['net'], $now['bills_count'])) : ''))
                ->chart($week->pluck('net')->values()->all())
                ->color('success'),
            Stat::make('Last 7 days', $money($weekNet))
                ->description($change === null ? $week->sum('bills_count').' bills' : ($change >= 0 ? '+' : '').$change.'% vs the 7 days before')
                ->color($change !== null && $change < 0 ? 'danger' : 'success'),
            Stat::make('Tables open now', $open->count())
                ->description($open->isEmpty() ? 'No one waiting to pay' : $money($unpaid).' not paid yet'),
            Stat::make('Cash today', $money($now['cash']))
                ->description('KHQR '.$money($now['khqr']).($now['card'] ? ' · Card '.$money($now['card']) : '').($now['refunds'] ? ' · Refunds '.$money($now['refunds']) : '')),
        ];
    }
}
